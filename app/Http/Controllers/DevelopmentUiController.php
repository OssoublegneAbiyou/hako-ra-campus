<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DevelopmentUiController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $page = max(1, min(2, $request->integer('page', 1)));

        return Inertia::render('ComponentLab', [
            'page' => $page,
            'examples' => $page === 1
                ? [
                    ['id' => 'exemple-1', 'title' => 'Relire un chapitre fictif', 'subject' => 'Méthodologie', 'source' => 'personal'],
                    ['id' => 'exemple-2', 'title' => 'Préparer une séance fictive', 'subject' => 'Organisation', 'source' => 'institutional'],
                ]
                : [['id' => 'exemple-3', 'title' => 'Classer des notes fictives', 'subject' => 'Organisation', 'source' => 'personal']],
        ]);
    }
}
