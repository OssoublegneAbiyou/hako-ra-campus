import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { CheckboxField, SelectField, TextAreaField, TextField } from '../components/ui/Fields';
import { StateMessage, ProvenanceBadge } from '../components/ui/Feedback';
import { Pagination } from '../components/ui/Collection';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { useUnsavedWarning } from '../components/ui/useUnsavedWarning';
import { MoreOptions } from '../components/ui/MoreOptions';
import { isoWeekday, openQuickAdd, shiftDate } from '../components/quickAddEvents';

type Option={id:number;name:string};
type Rules={title:string;kind:string;valid_from:string;valid_until:string;start_time:string;end_time:string;timezone:string;weekdays:number[];non_working_days:number[];excluded_dates:string[];active_weeks:string[];holidays:{from:string;until:string}[];group_ids:number[];subject_id:number|null;personal_subject_id:number|null;teacher_membership_id:number|null;room:string|null;meeting_url:string|null};
type Series={id:string;version:number;revisions:{version:number;effectiveFrom:string;rules:Rules}[]};
type Occurrence={id:string;seriesId:string;title:string;startsAt:string;endsAt:string;timezone:string;originalDate:string;status:string;exception:boolean;version:number;room:string|null;meetingUrl:string|null;provenance:'personal'|'institutional';groups:string[];checkIn:string|null;canCheckIn:boolean};
type Pager={page:number;total:number;previous:string|null;next:string|null};
type Report={id:number;occurrenceId:string;title:string;startsAt:string;status:'pending'|'accepted'|'declined';decision:string|null;note:string|null};
type Props={source:'personal'|'draft'|'published';canSwitchSource:boolean;publication:{version:number;publishedAt:string}|null;canPublish:boolean;canReport:boolean;reports:Report[];date:string;view:'day'|'week'|'list';search:string;optionSearch:string;timezone:string;institutional:boolean;editable:boolean;draftsHidden:boolean;rangeStart:string;rangeEnd:string;previousHref:string;nextHref:string;rows:Occurrence[];series:Series[];options:Record<string,Option[]>;pagination:Pager;seriesPagination:Pager};
const weekdays=['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
function PagerView({value}:{value:Pager}) {return <Pagination currentPage={value.page} totalPages={value.total} previousHref={value.previous??undefined} nextHref={value.next??undefined}/>;}
function splitDates(text:string) {return text.split(/[\s,;]+/).map(v=>v.trim()).filter(Boolean);}
function localDate(instant:string,timezone:string) {return new Intl.DateTimeFormat('en-CA',{timeZone:timezone,year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(instant));}
function localTime(instant:string,timezone:string) {return new Intl.DateTimeFormat('fr',{timeZone:timezone,hour:'2-digit',minute:'2-digit'}).format(new Date(instant));}
const HOUR=64;
type Placed={o:Occurrence;start:number;end:number;column:number;columns:number};
/** Place les cours d'une journée : minutes depuis minuit dans le fuseau affiché, et colonnes côte à côte pour ceux qui se chevauchent. */
function placeDay(rows:Occurrence[],day:string,timezone:string):Placed[] {
    const minutes=(instant:string)=>{const [h,m]=localTime(instant,timezone).split(':').map(Number);return h*60+m;};
    const items=rows.filter(o=>localDate(o.startsAt,timezone)<=day&&localDate(new Date(new Date(o.endsAt).getTime()-1).toISOString(),timezone)>=day).map(o=>{
        const start=localDate(o.startsAt,timezone)<day?0:minutes(o.startsAt);
        const end=localDate(new Date(new Date(o.endsAt).getTime()-1).toISOString(),timezone)>day?1440:Math.max(start+15,minutes(o.endsAt)||1440);
        return {o,start,end,column:0,columns:1};
    }).sort((a,b)=>a.start-b.start||a.end-b.end);
    let group:Placed[]=[];let groupEnd=-1;
    const close=()=>{const count=Math.max(1,...group.map(item=>item.column+1));group.forEach(item=>{item.columns=count;});group=[];};
    for(const item of items){
        if(item.start>=groupEnd)close();
        const taken=group.filter(other=>other.end>item.start).map(other=>other.column);
        let column=0;while(taken.includes(column))column++;
        item.column=column;group.push(item);groupEnd=Math.max(groupEnd,item.end);
    }
    close();
    return items;
}
/** Semaine en calendrier : une colonne par jour, les heures en marge, chaque cours à sa place et à sa durée. Un cours choisi affiche son détail dessous. */
function WeekCalendar({days,rows,timezone,picked,onPick,dayHref}:{days:string[];rows:Occurrence[];timezone:string;picked:string|null;onPick:(o:Occurrence)=>void;dayHref:(day:string)=>string}) {
    const placed=days.map(day=>placeDay(rows,day,timezone));
    const all=placed.flat();
    const first=Math.min(8,...all.map(item=>Math.floor(item.start/60)));
    const last=Math.max(18,...all.map(item=>Math.ceil(item.end/60)));
    const hours=Array.from({length:last-first},(_,i)=>first+i);
    const today=new Intl.DateTimeFormat('en-CA',{timeZone:timezone,year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const [nowHour,nowMinute]=new Intl.DateTimeFormat('fr',{timeZone:timezone,hour:'2-digit',minute:'2-digit'}).format(new Date()).split(':').map(Number);
    const now=nowHour*60+nowMinute;
    const height=(last-first)*HOUR;
    return <section className="calendar" aria-label="Cours de la semaine"><div className="calendar-grid" style={{gridTemplateColumns:`3.25rem repeat(${days.length}, minmax(6.5rem, 1fr))`}}>
        <div className="calendar-corner" />
        {days.map(day=>{const date=new Date(day+'T12:00:00Z');return <Link key={day} href={dayHref(day)} className={`calendar-head ${day===today?'is-today':''}`} aria-label={`Ouvrir la journée du ${new Intl.DateTimeFormat('fr',{weekday:'long',day:'numeric',month:'long',timeZone:'UTC'}).format(date)}`}><span>{new Intl.DateTimeFormat('fr',{weekday:'short',timeZone:'UTC'}).format(date)}</span><strong>{date.getUTCDate()}</strong></Link>;})}
        <div className="calendar-corner" />
        {days.map(day=><button type="button" key={day} className="calendar-add" aria-label={`Ajouter une tâche le ${new Intl.DateTimeFormat('fr',{weekday:'long',day:'numeric',month:'long',timeZone:'UTC'}).format(new Date(day+'T12:00:00Z'))}`} onClick={()=>openQuickAdd('task',`${day}T18:00`)}>+ Tâche</button>)}
        <div className="calendar-hours" style={{height}} aria-hidden="true">{hours.map(hour=><span key={hour} style={{top:(hour-first)*HOUR}}>{String(hour).padStart(2,'0')}:00</span>)}</div>
        {days.map((day,index)=><div key={day} className="calendar-column" style={{height,backgroundSize:`100% ${HOUR}px`}} title="Toucher une case vide pour ajouter une tâche" onClick={event=>{if(event.target!==event.currentTarget)return;const offset=event.nativeEvent.offsetY;const minutes=Math.min(23*60+30,first*60+Math.round(offset/HOUR*2)/2*60);openQuickAdd('task',`${day}T${String(Math.floor(minutes/60)).padStart(2,'0')}:${String(minutes%60).padStart(2,'0')}`);}}>
            {day===today&&now>=first*60&&now<=last*60&&<span className="calendar-now" style={{top:(now-first*60)/60*HOUR}} aria-hidden="true" />}
            {placed[index].map(({o,start,end,column,columns})=><button type="button" key={o.id} className={`calendar-event calendar-${o.provenance} ${o.status!=='active'?'is-off':''}`} aria-pressed={picked===o.id}
                style={{top:(start-first*60)/60*HOUR,height:Math.max(44,(end-start)/60*HOUR-2),left:`${column/columns*100}%`,width:`${100/columns}%`}} onClick={()=>onPick(o)}
                aria-label={`${o.title}, ${localTime(o.startsAt,timezone)} à ${localTime(o.endsAt,timezone)}${o.room?`, ${o.room}`:''}${o.status==='cancelled'?', annulé':''}`}>
                <strong>{o.title}</strong><span>{localTime(o.startsAt,timezone)} – {localTime(o.endsAt,timezone)}</span>{o.room&&<span>{o.room}</span>}{o.status==='cancelled'&&<span>Annulé</span>}
            </button>)}
        </div>)}
    </div></section>;
}
export default function Schedule(p:Props) {
    const [picked,setPicked]=useState<string|null>(null);
    useEffect(()=>{if(picked)document.getElementById('course-detail')?.scrollIntoView({block:'nearest'});},[picked]);
    const defaults={request_key:crypto.randomUUID(),title:'',kind:'weekly',valid_from:p.date,valid_until:p.institutional?p.date:shiftDate(p.date,181),start_time:'09:00',end_time:'10:00',timezone:p.timezone,weekdays:[isoWeekday(p.date)] as number[],non_working_days:[] as number[],excluded_dates:'',active_weeks:'',holidays:'',group_ids:[] as number[],subject_id:'',personal_subject_id:'',teacher_membership_id:'',room:'',meeting_url:'',effective_from:p.date,lock_version:1,reason:'',confirm_conflicts:false,conflict_reason:''};
    const form=useForm(defaults);
    useUnsavedWarning(form.data.title.trim()!==''&&!form.processing,'Votre cours');
    const [editing,setEditing]=useState<string|null>(null);
    const [query,setQuery]=useState(p.search);
    const [optionQuery,setOptionQuery]=useState(p.optionSearch);
    const [target,setTarget]=useState<Occurrence|null>(null);
    const [reporting,setReporting]=useState<Occurrence|null>(null);
    const [reportReason,setReportReason]=useState('');
    const [reportError,setReportError]=useState('');
    const [reportBusy,setReportBusy]=useState(false);
    const published=p.source==='published';
    const sourceQuery=published?{source:'published'}:{};
    const sourceSuffix=published?'&source=published':'';
    const pendingReports=new Set(p.reports.filter(r=>r.status==='pending').map(r=>r.occurrenceId));
    function sendReport() {
        if(!reporting)return;
        setReportBusy(true);
        router.post(`/planning/occurrences/${reporting.id}/indisponibilite`,{reason:reportReason},{preserveScroll:true,onSuccess:()=>{setReporting(null);setReportReason('');setReportError('');},onError:errors=>setReportError(Object.values(errors).join(' ')),onFinish:()=>setReportBusy(false)});
    }
    useEffect(()=>{if(target){const editor=document.getElementById('occurrence-form');editor?.scrollIntoView({block:'start'});editor?.focus();}},[target]);
    const change=useForm({action:'reschedule',date:p.date,start_time:'09:00',end_time:'10:00',room:'',meeting_url:'',reason:'',lock_version:1,confirm_conflicts:false,conflict_reason:''});
    const windowForm=useForm({series_id:'',window_from:p.date,window_until:p.date,confirm_conflicts:false,conflict_reason:''});
    const errors=form.errors as Record<string,string>;
    const reset=()=>{form.setData({...defaults,request_key:crypto.randomUUID()});form.clearErrors();setEditing(null);};
    function submit(e:FormEvent) {
        e.preventDefault();form.transform(d=>({...d,valid_until:d.kind==='once'?d.valid_from:d.valid_until,subject_id:d.subject_id?Number(d.subject_id):null,personal_subject_id:d.personal_subject_id?Number(d.personal_subject_id):null,teacher_membership_id:d.teacher_membership_id?Number(d.teacher_membership_id):null,excluded_dates:splitDates(d.excluded_dates),active_weeks:splitDates(d.active_weeks),holidays:d.holidays.split(/\n/).map(v=>v.trim()).filter(Boolean).map(v=>{const [from,until]=v.split('/');return {from,until};})}));
        const options={preserveScroll:true,onSuccess:reset};
        if(editing)form.patch(`/planning/series/${editing}/futur`,options);else form.post('/planning/series',options);
    }
    function editSeries(s:Series) {
        const rules=s.revisions[s.revisions.length-1].rules;
        form.setData({...defaults,...rules,room:rules.room??'',meeting_url:rules.meeting_url??'',subject_id:rules.subject_id?String(rules.subject_id):'',personal_subject_id:rules.personal_subject_id?String(rules.personal_subject_id):'',teacher_membership_id:rules.teacher_membership_id?String(rules.teacher_membership_id):'',excluded_dates:rules.excluded_dates.join('\n'),active_weeks:rules.active_weeks.join('\n'),holidays:rules.holidays.map(v=>`${v.from}/${v.until}`).join('\n'),lock_version:s.version});
        form.clearErrors();setEditing(s.id);document.getElementById('series-form')?.scrollIntoView({block:'start'});
    }
    function editOccurrence(o:Occurrence) {setTarget(o);change.setData({action:'reschedule',date:localDate(o.startsAt,o.timezone),start_time:localTime(o.startsAt,o.timezone),end_time:localTime(o.endsAt,o.timezone),room:o.room??'',meeting_url:o.meetingUrl??'',reason:'',lock_version:o.version,confirm_conflicts:false,conflict_reason:''});change.clearErrors();}
    const days=Array.from({length:p.view==='day'?1:7},(_,i)=>{const d=new Date(p.rangeStart+'T12:00:00Z');d.setUTCDate(d.getUTCDate()+i);return d.toISOString().slice(0,10);});
    const day=(value:string)=>new Intl.DateTimeFormat('fr',{dateStyle:'medium',timeZone:'UTC'}).format(new Date(value+'T12:00:00Z'));
    const overlapped=(found:Record<string,string>)=>Boolean(found.confirm_conflicts||found.conflict_reason||(found.conflict??'').startsWith('Chevauchement'));
    const overlap=overlapped(errors);
    const shown=Object.entries(errors).filter(([key,message])=>key!=='confirm_conflicts'&&key!=='conflict_reason'&&!(key==='conflict'&&message.startsWith('Chevauchement')));
    const text=(key:'title'|'valid_from'|'valid_until'|'start_time'|'end_time'|'timezone'|'room'|'meeting_url'|'effective_from'|'reason'|'conflict_reason',label:string,type='text')=><TextField label={label} type={type} value={form.data[key]} onChange={e=>form.setData(key,e.target.value)} error={errors[key]}/>;
    const choose=(key:'subject_id'|'personal_subject_id'|'teacher_membership_id',label:string,source:string)=><SelectField label={label} value={form.data[key]} onChange={e=>form.setData(key,e.target.value)} error={errors[key]}><option value="">Sélectionner</option>{(p.options[source]??[]).map(o=><option value={o.id} key={o.id}>{o.name}</option>)}</SelectField>;
    const shownTime=(o:Occurrence,instant:string)=>localDate(o.startsAt,p.timezone)===localDate(new Date(new Date(o.endsAt).getTime()-1).toISOString(),p.timezone)?localTime(instant,p.timezone):new Intl.DateTimeFormat('fr',{dateStyle:'short',timeStyle:'short',timeZone:p.timezone}).format(new Date(instant));
    const card=(o:Occurrence)=><article className={`schedule-card schedule-${o.status}`} key={o.id}><ProvenanceBadge source={o.provenance}/>{p.institutional&&<span className="provenance">{published?`Publié · version ${o.version}`:'Brouillon'}</span>}<h3>{o.title}</h3><p><time dateTime={o.startsAt}>{shownTime(o,o.startsAt)}</time> – <time dateTime={o.endsAt}>{shownTime(o,o.endsAt)}</time></p><p>{o.status==='cancelled'?'Annulé':o.status==='calendar_excluded'?'Jour sans cours':'Prévu'}{o.exception?' · modifié à la main':''}</p>{o.groups.length>0&&<p>{o.groups.join(', ')}</p>}{o.room&&<p>Salle : {o.room}</p>}{o.timezone!==p.timezone&&<p>Fuseau d’origine : {o.timezone}</p>}{o.meetingUrl&&<a className="text-link" href={o.meetingUrl} target="_blank" rel="noopener noreferrer">Lien du cours</a>}{o.canCheckIn&&<Link className="text-link" href={`/planning/occurrences/${o.id}/retour`}>{o.checkIn==='completed'||o.checkIn==='nothing'?'Voir mon retour privé':o.checkIn==='deferred'?'Reprendre mon retour':'Renseigner mon retour'}</Link>}{(o.canCheckIn||o.provenance==='personal')&&o.status==='active'&&<Link className="text-link" href={`/taches?cours=${o.id}`}>Ajouter une tâche</Link>}{!published&&<Link className="text-link" href={`/planning/occurrences/${o.id}/historique`}>Historique</Link>}{p.editable&&<Button variant="secondary" onClick={()=>editOccurrence(o)}>Modifier / annuler</Button>}{p.canReport&&o.status==='active'&&new Date(o.startsAt)>new Date()&&(pendingReports.has(o.id)?<p>Indisponibilité signalée, en attente de décision.</p>:<Button variant="secondary" onClick={()=>{setReporting(o);setReportError('');}}>Signaler mon indisponibilité</Button>)}</article>;
    return <CampusLayout active="planning" title="Planning"><Head title="Planning"/><div className="page-heading"><p className="eyebrow">{!p.institutional?'MON PLANNING PERSONNEL':published?'PLANNING INSTITUTIONNEL · PUBLIÉ':'PLANNING INSTITUTIONNEL · BROUILLON'}</p><h1>Votre planning</h1><p className="page-intro">{p.rangeStart===p.rangeEnd?day(p.rangeStart):`Du ${day(p.rangeStart)} au ${day(p.rangeEnd)}`}</p></div>
    {p.institutional&&<StateMessage kind="info" title={published?(p.publication?`Planning publié — version ${p.publication.version}`:'Aucun planning publié pour l’instant'):'Brouillon : non visible des apprenants'} action={<span className="form-actions">{p.canSwitchSource&&<Link className="text-link" href={`/planning?date=${p.date}&view=${p.view}&source=${published?'draft':'published'}`}>{published?'Voir le brouillon':'Voir le planning publié'}</Link>}{p.canPublish&&<Link className="text-link" href="/planning/import">Importer un fichier Excel</Link>}{p.canPublish&&<Link className="text-link" href="/planning/publication">Prévisualiser et publier</Link>}</span>}>{p.publication?`Dernière publication le ${new Intl.DateTimeFormat('fr',{dateStyle:'long',timeStyle:'short',timeZone:p.timezone}).format(new Date(p.publication.publishedAt))}.`:'Les membres ne voient aucun cours tant qu’une version n’est pas publiée.'} {published?'Seule une publication de l’administration modifie ce planning.':'Les modifications ci-dessous restent dans le brouillon jusqu’à leur publication.'}</StateMessage>}
    <section className="surface"><form className="filter-bar" onSubmit={e=>{e.preventDefault();router.get('/planning',{date:p.date,view:p.view,q:query,...sourceQuery});}}><TextField label="Date à afficher" type="date" value={p.date} onChange={e=>router.get('/planning',{date:e.target.value,view:p.view,...sourceQuery})}/><TextField label="Rechercher un cours" value={query} onChange={e=>setQuery(e.target.value)}/><Button type="submit">Rechercher</Button></form><nav className="form-actions" aria-label="Affichage du planning">{(['day','week','list'] as const).map(view=><Link href={`/planning?date=${p.date}&view=${view}${sourceSuffix}`} aria-current={p.view===view?'page':undefined} key={view}>{view==='day'?'Jour':view==='week'?'Semaine':'Liste'}</Link>)}<Link href={p.previousHref}>Période précédente</Link><Link href={p.nextHref}>Période suivante</Link>{(!p.institutional||p.draftsHidden)&&<Link href="/retours">Mes retours de cours</Link>}</nav></section>
    {p.rows.length===0?<StateMessage kind="empty" title="Aucun cours sur cette période">{p.editable?'Ajoutez un cours ci-dessous.':'Aucun cours à afficher ici.'}</StateMessage>:p.view==='week'?<><WeekCalendar days={days} rows={p.rows} timezone={p.timezone} picked={picked} onPick={o=>setPicked(picked===o.id?null:o.id)} dayHref={d=>`/planning?date=${d}&view=day${sourceSuffix}`}/>{p.rows.filter(o=>o.id===picked).map(o=><section className="surface" id="course-detail" key={o.id} aria-label="Détail du cours">{card(o)}<Button variant="secondary" onClick={()=>setPicked(null)}>Fermer</Button></section>)}{picked===null&&<p className="item-meta">Touchez un cours pour voir son détail, ou une case vide pour ajouter une tâche à cette heure.</p>}</>:<section className="schedule-list" aria-label="Cours de la période">{days.map(day=><section className="schedule-day" key={day}><h2>{new Intl.DateTimeFormat('fr',{weekday:'long',day:'numeric',month:'short',timeZone:'UTC'}).format(new Date(day+'T12:00:00Z'))}</h2>{p.rows.filter(o=>localDate(o.startsAt,p.timezone)<=day&&localDate(new Date(new Date(o.endsAt).getTime()-1).toISOString(),p.timezone)>=day).map(card)}</section>)}</section>}
    <PagerView value={p.pagination}/>
    {p.reports.length>0&&<section className="surface"><h2>Mes signalements d’indisponibilité</h2><ul className="plain-list">{p.reports.map(r=><li key={r.id}><strong>{r.title}</strong><p className="item-meta">{new Intl.DateTimeFormat('fr',{dateStyle:'medium',timeStyle:'short',timeZone:p.timezone}).format(new Date(r.startsAt))} · {r.status==='pending'?'En attente de décision de l’administration':r.decision}{r.note?` — ${r.note}`:''}</p></li>)}</ul></section>}
    <ConfirmDialog open={reporting!==null} busy={reportBusy} title={`Signaler votre indisponibilité pour « ${reporting?.title??''} » ?`} confirmLabel="Envoyer le signalement" onCancel={()=>setReporting(null)} onConfirm={sendReport}><TextAreaField label="Motif transmis à l’administration" hint="Trois caractères minimum. Ne saisissez pas d’information médicale détaillée." value={reportReason} onChange={e=>setReportReason(e.target.value)} error={reportError}/>Le cours reste affiché tel quel. Seule une publication de l’administration peut l’annuler, le reporter ou le remplacer.</ConfirmDialog>
    {target&&p.editable&&<section className="surface" id="occurrence-form" tabIndex={-1}><h2>Modifier ou annuler : {target.title}</h2><form className="form-narrow" onSubmit={e=>{e.preventDefault();change.patch(`/planning/occurrences/${target.id}`,{preserveScroll:true,onSuccess:()=>setTarget(null)});}}>{Object.keys(change.errors).length>0&&<StateMessage kind="error" title="Modification refusée">{Object.values(change.errors).join(' ')}</StateMessage>}<SelectField label="Action" value={change.data.action} onChange={e=>change.setData('action',e.target.value)}><option value="reschedule">Reporter / modifier / rétablir</option><option value="cancel">Annuler le cours</option></SelectField>{change.data.action==='reschedule'&&<><TextField label="Nouvelle date" type="date" value={change.data.date} onChange={e=>change.setData('date',e.target.value)}/><TextField label="Nouveau début" type="time" value={change.data.start_time} onChange={e=>change.setData('start_time',e.target.value)}/><TextField label="Nouvelle fin" type="time" value={change.data.end_time} onChange={e=>change.setData('end_time',e.target.value)}/><TextField label="Salle de cette occurrence" value={change.data.room} onChange={e=>change.setData('room',e.target.value)}/><TextField label="Lien de cette occurrence" type="url" value={change.data.meeting_url} onChange={e=>change.setData('meeting_url',e.target.value)}/></>}<TextAreaField label="Motif de modification ou d’annulation" required value={change.data.reason} onChange={e=>change.setData('reason',e.target.value)}/>{(overlapped(change.errors as Record<string,string>)||change.data.confirm_conflicts)&&<><CheckboxField label="Confirmer un chevauchement signalé" checked={change.data.confirm_conflicts} onChange={e=>change.setData('confirm_conflicts',e.target.checked)}/><TextField label="Justification du chevauchement (10 caractères minimum)" value={change.data.conflict_reason} onChange={e=>change.setData('conflict_reason',e.target.value)}/></>}<div className="form-actions"><Button type="submit" busy={change.processing}>Confirmer la modification</Button><Button variant="secondary" onClick={()=>setTarget(null)}>Fermer</Button></div></form></section>}
    {p.editable&&<><section className="surface" id="series-form"><h2>{editing?'Modifier ce cours pour la suite':'Ajouter un cours'}</h2>
    <form className="form-narrow" onSubmit={submit} noValidate>
    {shown.length>0&&<StateMessage kind="error" title="Cours non enregistré"><ul>{shown.map(([key,error])=><li key={key}>{error}</li>)}</ul></StateMessage>}
    {overlap&&<StateMessage kind="error" title="Ce cours en chevauche un autre">Changez l’horaire, ou confirmez le chevauchement ci-dessous.</StateMessage>}
    {text('title','Titre')}
    <SelectField label="Répétition" value={form.data.kind} onChange={e=>form.setData('kind',e.target.value)}><option value="weekly">Chaque semaine</option><option value="once">Une seule fois</option></SelectField>
    {form.data.kind==='weekly'&&<fieldset><legend>Jours</legend><div className="schedule-checkboxes">{weekdays.map((label,i)=><CheckboxField label={label} key={label} checked={form.data.weekdays.includes(i+1)} onChange={e=>form.setData('weekdays',e.target.checked?[...form.data.weekdays,i+1]:form.data.weekdays.filter(v=>v!==i+1))}/>)}</div></fieldset>}
    {text('valid_from',form.data.kind==='weekly'?'Date de début':'Date','date')}{form.data.kind==='weekly'&&text('valid_until','Date de fin','date')}
    {text('start_time','Début','time')}{text('end_time','Fin','time')}
    {p.institutional&&<>{choose('subject_id','Matière','subjects')}{choose('teacher_membership_id','Enseignant','teachers')}<fieldset><legend>Groupes concernés</legend>{(p.options.groups??[]).map(o=><CheckboxField label={o.name} key={o.id} checked={form.data.group_ids.includes(o.id)} onChange={e=>form.setData('group_ids',e.target.checked?[...form.data.group_ids,o.id]:form.data.group_ids.filter(v=>v!==o.id))}/>)}</fieldset></>}
    {editing&&<>{text('effective_from','À partir du','date')}{text('reason','Pourquoi ce changement ?')}</>}
    {(overlap||form.data.confirm_conflicts)&&<><CheckboxField label="Confirmer les chevauchements signalés" hint="Un doublon exact reste interdit." checked={form.data.confirm_conflicts} onChange={e=>form.setData('confirm_conflicts',e.target.checked)}/>{text('conflict_reason','Justification du chevauchement (10 caractères minimum)')}</>}
    <MoreOptions label={p.institutional?'Plus d’options : salle, lien, vacances':'Plus d’options : matière, salle, lien, vacances'} reveal={Boolean(editing||errors.personal_subject_id||errors.room||errors.meeting_url||errors.timezone||errors.excluded_dates||errors.active_weeks||errors.holidays)}>
        {!p.institutional&&(p.options.subjects??[]).length>0&&choose('personal_subject_id','Matière','subjects')}
        {text('room','Salle')}{text('meeting_url','Lien du cours en ligne','url')}
        {!p.institutional&&text('timezone','Fuseau horaire')}
        {form.data.kind==='weekly'&&<><fieldset><legend>Jours sans cours</legend><div className="schedule-checkboxes">{weekdays.map((label,i)=><CheckboxField label={label} key={label} checked={form.data.non_working_days.includes(i+1)} onChange={e=>form.setData('non_working_days',e.target.checked?[...form.data.non_working_days,i+1]:form.data.non_working_days.filter(v=>v!==i+1))}/>)}</div></fieldset>
        <TextAreaField label="Dates sans cours" hint="AAAA-MM-JJ, une par ligne." value={form.data.excluded_dates} onChange={e=>form.setData('excluded_dates',e.target.value)}/>
        <TextAreaField label="Vacances" hint="Une plage par ligne : AAAA-MM-JJ/AAAA-MM-JJ." value={form.data.holidays} onChange={e=>form.setData('holidays',e.target.value)}/>
        <TextAreaField label="Seulement certaines semaines" hint="Le lundi de chaque semaine voulue, AAAA-MM-JJ. Vide : toutes les semaines." value={form.data.active_weeks} onChange={e=>form.setData('active_weeks',e.target.value)}/></>}
    </MoreOptions>
    <div className="form-actions"><Button type="submit" busy={form.processing}>{editing?'Enregistrer le changement':'Ajouter le cours'}</Button>{(editing||form.data.title!=='')&&<Button variant="secondary" onClick={reset}>Annuler</Button>}</div></form>
    {((p.options.subjects??[]).length>=200||p.optionSearch!=='')&&<form className="form-narrow" onSubmit={e=>{e.preventDefault();router.get('/planning',{date:p.date,view:p.view,option_q:optionQuery},{preserveState:true,preserveScroll:true});}}><TextField label="Chercher une matière, un groupe ou un enseignant à proposer" value={optionQuery} onChange={e=>setOptionQuery(e.target.value)}/><Button type="submit" variant="secondary">Chercher</Button></form>}
    </section>
    {p.series.length>0&&<section className="surface"><h2>Cours enregistrés</h2><ul className="plain-list">{p.series.map(s=>{const rules=s.revisions[s.revisions.length-1]?.rules;return <li key={s.id}><strong>{rules?.title??'Cours'}</strong>{rules&&<p className="item-meta">{rules.kind==='weekly'?`Chaque ${rules.weekdays.map(d=>weekdays[d-1].toLowerCase()).join(', ')} · du ${day(rules.valid_from)} au ${day(rules.valid_until)}`:`Le ${day(rules.valid_from)}`} · {rules.start_time}–{rules.end_time}</p>}<Button variant="secondary" onClick={()=>editSeries(s)}>Modifier pour la suite</Button></li>;})}</ul><PagerView value={p.seriesPagination}/>
    <MoreOptions label="Un cours n’apparaît pas à une date ? L’afficher sur une autre période" reveal={Object.keys(windowForm.errors).length>0}><form className="form-narrow" onSubmit={e=>{e.preventDefault();windowForm.post(`/planning/series/${windowForm.data.series_id}/fenetre`,{preserveScroll:true});}}><p className="field-hint">Un cours régulier est d’abord affiché sur huit semaines. Choisissez ici une autre période, de huit semaines au plus.</p><SelectField label="Cours" value={windowForm.data.series_id} onChange={e=>windowForm.setData('series_id',e.target.value)}><option value="">Choisir</option>{p.series.map(s=><option key={s.id} value={s.id}>{s.revisions[s.revisions.length-1]?.rules.title}</option>)}</SelectField><TextField label="Du" type="date" value={windowForm.data.window_from} onChange={e=>windowForm.setData('window_from',e.target.value)}/><TextField label="Au" type="date" value={windowForm.data.window_until} onChange={e=>windowForm.setData('window_until',e.target.value)}/>{(overlapped(windowForm.errors as Record<string,string>)||windowForm.data.confirm_conflicts)&&<><CheckboxField label="Confirmer les chevauchements de cette période" checked={windowForm.data.confirm_conflicts} onChange={e=>windowForm.setData('confirm_conflicts',e.target.checked)}/><TextField label="Justification du chevauchement (10 caractères minimum)" value={windowForm.data.conflict_reason} onChange={e=>windowForm.setData('conflict_reason',e.target.value)}/></>}{Object.keys(windowForm.errors).length>0&&<StateMessage kind="error" title="Cours non affichés">{Object.values(windowForm.errors).join(' ')}</StateMessage>}<Button type="submit" busy={windowForm.processing} disabled={!windowForm.data.series_id}>Afficher</Button></form></MoreOptions></section>}
    </>}
    </CampusLayout>;
}
