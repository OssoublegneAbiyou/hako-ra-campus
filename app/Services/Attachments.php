<?php

namespace App\Services;

use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\AttachmentReport;
use App\Models\AuditLog;
use App\Models\CourseCheckIn;
use App\Models\Membership;
use App\Models\PersonalSpace;
use App\Models\Task;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Support\PrivilegedAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pièces jointes privées. À chaque opération, le parent (tâche, devoir, retour de cours) et le propriétaire sont recontrôlés.
 * Un fichier « personnel » n'est lisible que par son propriétaire, quel que soit le parent ; un fichier « groupe » suit
 * les droits du devoir. Aucun fichier n'est diffusé tant que l'antivirus ne l'a pas déclaré sain.
 */
class Attachments
{
    private const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(private ContextVisibility $visibility, private Tasks $tasks) {}

    /**
     * Contrôle le fichier reçu : taille, extension, type réel lu dans le contenu, cohérence des deux, absence de contenu actif.
     *
     * @return array{name: string, extension: string, mime: string, size: int}
     */
    public function inspect(UploadedFile $file): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['file' => $message]);
        if (! $file->isValid()) {
            $fail('Le fichier n’a pas pu être reçu. Réessayez.');
        }
        $size = (int) $file->getSize();
        $max = (int) config('campus.attachments.max_file_kilobytes');
        if ($size <= 0) {
            $fail('Ce fichier est vide.');
        }
        if ($size > $max * 1024) {
            $fail('Ce fichier dépasse la taille autorisée ('.round($max / 1024, 1).' Mo).');
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $file->getClientOriginalName()));
        $name = mb_substr($name === '' ? 'fichier' : $name, -200);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$extension])) {
            $fail('Seuls les fichiers PDF, JPEG et PNG sont acceptés.');
        }
        $path = $file->getRealPath();
        // Le type vient du contenu, jamais de ce qu'annonce le navigateur.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $signature = match (self::TYPES[$extension]) {
            'application/pdf' => str_starts_with($head, '%PDF-'),
            'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            default => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
        };
        if ($mime !== self::TYPES[$extension] || ! $signature) {
            $fail('Le contenu de ce fichier ne correspond pas à son extension. Il est refusé.');
        }
        if ($extension === 'pdf') {
            // Noms PDF éventuellement écrits en hexadécimal (#4A#53) ramenés en clair avant la recherche de contenu actif.
            $content = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $match) => chr((int) hexdec($match[1])), (string) file_get_contents($path));
            if (preg_match('#/(JavaScript|Launch|EmbeddedFile|RichMedia|XFA|OpenAction)\b|/(JS|AA)[\s(<\[/]#', $content) === 1) {
                $fail('Ce PDF contient un élément actif (script, action automatique ou fichier embarqué). Il est refusé.');
            }
        } else {
            $image = @getimagesize($path);
            if ($image === false || $image[2] !== ($extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG) || $image[0] < 1 || $image[1] < 1
                || (int) config('campus.attachments.max_image_pixels') < $image[0] * $image[1]) {
                $fail('Cette image est illisible ou trop grande. Elle est refusée.');
            }
        }

        return ['name' => $name, 'extension' => $extension, 'mime' => self::TYPES[$extension], 'size' => $size];
    }

    /**
     * Dépose un fichier sur une tâche, un devoir ou un retour de cours. Les limites par objet et par compte sont vérifiées
     * sous verrou : deux dépôts simultanés du même compte passent l'un après l'autre et ne dépassent jamais le quota.
     *
     * @return array{attachment: Attachment, replayed: bool}
     */
    public function store(User $user, Task|CourseCheckIn $parent, UploadedFile $file, string $visibility, string $clientKey): array
    {
        $checked = $this->inspect($file);
        $stored = null;
        try {
            $result = DB::transaction(function () use ($user, $parent, $file, $visibility, $clientKey, $checked, &$stored): array {
                $space = PersonalSpace::query()->where('owner_id', $user->id)->lockForUpdate()->firstOrFail();
                $this->visibility->personalSpace($user, $space->id);
                $existing = Attachment::query()->where('owner_id', $user->id)->where('client_key', $clientKey)->first();
                if ($existing) {
                    return ['attachment' => $existing, 'replayed' => true];
                }
                $this->authorizeDeposit($user, $parent, $visibility);
                $column = $parent instanceof Task ? 'task_id' : 'course_check_in_id';
                $onObject = Attachment::query()->where($column, $parent->getKey())->whereNull('deleted_at')->where('visibility', $visibility)
                    ->when($visibility === 'personal', fn ($query) => $query->where('owner_id', $user->id))->count();
                if ($onObject >= (int) config('campus.attachments.max_files_per_object')) {
                    throw ValidationException::withMessages(['file' => 'Le nombre maximal de fichiers est atteint pour cet élément ('.config('campus.attachments.max_files_per_object').').']);
                }
                if ($this->usage($user) + $checked['size'] > (int) config('campus.attachments.max_user_megabytes') * 1024 * 1024) {
                    throw ValidationException::withMessages(['file' => 'Votre espace de pièces jointes est plein ('.config('campus.attachments.max_user_megabytes').' Mo). Supprimez des fichiers pour en ajouter.']);
                }
                // Nom de stockage aléatoire, sans extension : il ne révèle ni le nom affiché ni le type, et rien ne peut l'exécuter.
                $stored = bin2hex(random_bytes(32));
                if (Storage::disk('local')->putFileAs('attachments', $file, $stored) === false) {
                    throw ValidationException::withMessages(['file' => 'Le fichier n’a pas pu être enregistré. Réessayez.']);
                }
                $attachment = Attachment::create([$column => $parent->getKey(), 'owner_id' => $user->id, 'visibility' => $visibility, 'display_name' => $checked['name'],
                    'stored_name' => $stored, 'mime' => $checked['mime'], 'extension' => $checked['extension'], 'size_bytes' => $checked['size'],
                    'sha256' => hash_file('sha256', $file->getRealPath()), 'client_key' => $clientKey]);

                return ['attachment' => $attachment, 'replayed' => false];
            }, 3);
        } catch (\Throwable $error) {
            if ($stored !== null) {
                Storage::disk('local')->delete('attachments/'.$stored);
            }
            throw $error;
        }
        if (! $result['replayed']) {
            ScanAttachment::dispatch($result['attachment']->id);
        }

        return $result;
    }

    /**
     * Le compte peut-il déposer ici, avec cette visibilité ? Fichier personnel : parent lisible par lui. Fichier de groupe :
     * devoir qu'il gère comme enseignant affecté.
     */
    private function authorizeDeposit(User $user, Task|CourseCheckIn $parent, string $visibility): void
    {
        if ($parent instanceof CourseCheckIn) {
            abort_unless($visibility === 'personal' && $parent->owner_id === $user->id, 404);

            return;
        }
        abort_if($parent->deleted_at !== null, 404);
        if ($visibility === 'personal') {
            abort_unless($this->tasks->visible($user)->where('tasks.id', $parent->id)->exists(), 404);

            return;
        }
        abort_unless($parent->scope === 'group' && $this->manages($user, $parent), 404);
        abort_unless(PrivilegedAccess::available(), 403);
    }

    /**
     * Droit de lecture, recontrôlé à chaque accès, indépendamment de l'état antivirus. Connaître l'identifiant ne donne aucun droit.
     */
    public function canRead(User $user, Attachment $attachment): bool
    {
        if ($attachment->deleted_at !== null) {
            return false;
        }
        if ($attachment->course_check_in_id !== null) {
            return $attachment->visibility === 'personal' && $attachment->owner_id === $user->id
                && CourseCheckIn::query()->whereKey($attachment->course_check_in_id)->where('owner_id', $user->id)->exists();
        }
        $task = Task::query()->find($attachment->task_id);
        if ($task === null || $task->deleted_at !== null) {
            return false;
        }
        $visible = fn (): bool => $this->tasks->visible($user)->where('tasks.id', $task->id)->exists();
        if ($attachment->visibility === 'personal') {
            return $attachment->owner_id === $user->id && $visible();
        }
        // Un fichier de groupe n'est lisible que si le parent est encore un devoir de groupe et n'a pas été retiré de la diffusion.
        if ($task->scope !== 'group' || $attachment->withdrawn_at !== null) {
            return false;
        }

        return $visible() || $this->manages($user, $task);
    }

    /**
     * L'administration de l'établissement lit un fichier de groupe uniquement s'il fait l'objet d'un signalement, pour le traiter.
     */
    public function canReview(User $user, Attachment $attachment): bool
    {
        if (! PrivilegedAccess::available() || $attachment->deleted_at !== null || $attachment->visibility !== 'group') {
            return false;
        }
        $institutions = $this->visibility->usableMemberships($user)->where('scope', 'institution')->where('role', 'institution_admin')->pluck('institution_id');

        return AttachmentReport::query()->where('attachment_id', $attachment->id)->whereIn('institution_id', $institutions)->exists();
    }

    /**
     * Fichier à télécharger par ce compte. Introuvable sans droit ; refusé tant que l'antivirus ne l'a pas déclaré sain.
     */
    public function downloadable(User $user, string $attachmentId): Attachment
    {
        $attachment = Attachment::query()->findOrFail($attachmentId);
        abort_unless($this->canRead($user, $attachment) || $this->canReview($user, $attachment), 404);
        if ($attachment->scan_status !== 'clean') {
            // 423 « verrouillé » : le fichier existe et le compte y a droit, mais il n'est pas diffusable tant qu'il n'est pas déclaré sain.
            throw new HttpException(423, match ($attachment->scan_status) {
                'rejected' => 'Ce fichier a été refusé par l’antivirus. Il ne peut pas être téléchargé.',
                'error' => 'L’analyse antivirus n’a pas pu aboutir. Le fichier reste bloqué jusqu’à une analyse réussie.',
                default => 'Ce fichier est en cours d’analyse antivirus. Réessayez dans quelques instants.',
            });
        }
        abort_unless($attachment->file_removed_at === null && Storage::disk('local')->exists($attachment->path()), 404);

        return $attachment;
    }

    /**
     * Supprime une pièce jointe : son propriétaire, ou pour un fichier de groupe un enseignant qui gère le devoir.
     * Le fichier est retiré du stockage tout de suite ; l'espace est rendu au quota.
     */
    public function delete(User $user, string $attachmentId): Attachment
    {
        return DB::transaction(function () use ($user, $attachmentId): Attachment {
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($attachmentId);
            $task = $attachment->task_id ? Task::query()->find($attachment->task_id) : null;
            $manager = $attachment->visibility === 'group' && $task !== null && $this->manages($user, $task) && PrivilegedAccess::available();
            abort_unless($attachment->owner_id === $user->id || $manager, 404);
            abort_if($attachment->visibility === 'group' && ! $manager, 404);
            if ($attachment->deleted_at === null) {
                $attachment->forceFill(['deleted_at' => now()])->save();
                $this->removeFile($attachment);
            }

            return $attachment;
        }, 3);
    }

    /**
     * Signale un fichier partagé avec le groupe. Un seul signalement ouvert par personne et par fichier.
     */
    public function report(User $user, string $attachmentId, string $reason): AttachmentReport
    {
        return DB::transaction(function () use ($user, $attachmentId, $reason): AttachmentReport {
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($attachmentId);
            abort_unless($attachment->visibility === 'group' && $this->canRead($user, $attachment), 404);
            abort_if($attachment->owner_id === $user->id, 403);
            $existing = AttachmentReport::query()->where('attachment_id', $attachment->id)->where('reporter_id', $user->id)->where('status', 'open')->first();

            return $existing ?? AttachmentReport::create(['attachment_id' => $attachment->id, 'reporter_id' => $user->id, 'reason' => trim($reason),
                'institution_id' => Task::query()->whereKey($attachment->task_id)->value('institution_id')]);
        }, 3);
    }

    /**
     * Décision de l'administration sur un signalement : retrait du fichier de la diffusion, ou classement sans suite.
     */
    public function decide(User $user, Membership $admin, int $reportId, string $decision, ?string $note): AttachmentReport
    {
        return DB::transaction(function () use ($user, $admin, $reportId, $decision, $note): AttachmentReport {
            abort_unless(PrivilegedAccess::available(), 403);
            $admin = $this->visibility->administeredMembership($user, $admin->id);
            $report = AttachmentReport::query()->where('institution_id', $admin->institution_id)->lockForUpdate()->findOrFail($reportId);
            if ($report->status !== 'open') {
                return $report;
            }
            $closing = ['status' => $decision, 'open_slot' => null, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note === null || trim($note) === '' ? null : trim($note)];
            if ($decision === 'upheld') {
                $attachment = Attachment::query()->lockForUpdate()->findOrFail($report->attachment_id);
                if ($attachment->withdrawn_at === null) {
                    $attachment->forceFill(['withdrawn_at' => now()])->save();
                }
                // Tous les signalements ouverts du même fichier sont clos par le retrait.
                AttachmentReport::query()->where('attachment_id', $attachment->id)->where('status', 'open')->where('id', '!=', $report->id)->update($closing);
            }
            $report->forceFill($closing)->save();
            AuditLog::record($user, 'attachment.report.'.$decision, $report, $admin->institution_id, $closing['decision_note'], ['attachment_id' => $report->attachment_id]);

            return $report;
        }, 3);
    }

    /**
     * Pièces jointes à afficher pour des tâches que l'appelant a déjà vérifiées comme lisibles ou gérées par ce compte :
     * ses fichiers personnels et les fichiers de groupe.
     *
     * @param  iterable<string>  $taskIds
     * @return Collection<string, list<array<string, mixed>>>
     */
    public function forTasks(User $user, iterable $taskIds, bool $managing = false): Collection
    {
        return Attachment::query()->whereIn('task_id', collect($taskIds)->all())->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('visibility', 'group')->when(! $managing, fn ($group) => $group->whereNull('withdrawn_at'))
                ->orWhere(fn ($own) => $own->where('visibility', 'personal')->where('owner_id', $user->id)))
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('task_id')->map(fn (Collection $rows) => $rows->map(fn (Attachment $row) => $this->row($user, $row, $managing))->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCheckIn(User $user, CourseCheckIn $checkIn): array
    {
        abort_unless($checkIn->owner_id === $user->id, 404);

        return Attachment::query()->where('course_check_in_id', $checkIn->id)->where('owner_id', $user->id)->whereNull('deleted_at')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Attachment $row) => $this->row($user, $row, false))->all();
    }

    /**
     * Octets occupés par les fichiers du compte encore présents dans le stockage. Calculé, donc toujours cohérent.
     */
    public function usage(User $user): int
    {
        return (int) Attachment::query()->where('owner_id', $user->id)->whereNull('deleted_at')->whereNull('file_removed_at')->sum('size_bytes');
    }

    /**
     * @return array{usedBytes: int, maxBytes: int, maxFileBytes: int, maxPerObject: int, scannerConfigured: bool}
     */
    public function limits(User $user): array
    {
        return ['usedBytes' => $this->usage($user), 'maxBytes' => (int) config('campus.attachments.max_user_megabytes') * 1024 * 1024,
            'maxFileBytes' => (int) config('campus.attachments.max_file_kilobytes') * 1024, 'maxPerObject' => (int) config('campus.attachments.max_files_per_object'),
            'scannerConfigured' => config('campus.attachments.scanner') !== 'none'];
    }

    public function removeFile(Attachment $attachment): void
    {
        if ($attachment->file_removed_at === null) {
            Storage::disk('local')->delete($attachment->path());
            $attachment->forceFill(['file_removed_at' => now()])->save();
        }
    }

    /**
     * Le compte est-il, maintenant, enseignant effectivement affecté au groupe et à la matière de ce devoir ?
     */
    public function manages(User $user, Task $task): bool
    {
        if ($task->scope !== 'group') {
            return false;
        }

        return $this->visibility->usableMemberships($user)->where('scope', 'institution')->where('role', 'teacher')
            ->where('institution_id', $task->institution_id)->where('academic_year_id', $task->academic_year_id)->get()
            ->contains(fn (Membership $teacher) => $this->tasks->assignments($user, $teacher)
                ->contains(fn (TeachingAssignment $pair) => $pair->group_id === $task->group_id && $pair->subject_id === $task->subject_id));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user, Attachment $attachment, bool $managing): array
    {
        $mine = $attachment->owner_id === $user->id;
        $shared = $attachment->visibility === 'group';

        return ['id' => $attachment->id, 'name' => $attachment->display_name, 'sizeBytes' => $attachment->size_bytes, 'shared' => $shared, 'mine' => $mine,
            'status' => $attachment->scan_status, 'statusLabel' => Attachment::SCAN_LABELS[$attachment->scan_status], 'withdrawn' => $attachment->withdrawn_at !== null,
            'downloadable' => $attachment->scan_status === 'clean' && $attachment->withdrawn_at === null,
            'canDelete' => $shared ? $managing : $mine, 'canReport' => $shared && ! $mine && ! $managing && $attachment->scan_status === 'clean'];
    }
}
