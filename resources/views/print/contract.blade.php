@php
  use App\Filament\Support\Ui;
  $e = $contract->employee;
  $terms = $contract->termsAt();
  $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
@endphp
@extends('print.layout')
@section('title', 'Contrat '.$contract->number)
@section('hint', 'Modèle à faire relire par le Responsable RH avant signature. Trois exemplaires (Manuel §2.4.4).')
@section('style')
  @page { size: A4; margin: 20mm 18mm; }
  .sheet { width: 210mm; min-height: 297mm; padding: 20mm 18mm; font-size: 11pt; line-height: 1.5; }
  h1 { color: var(--navy); font-size: 16pt; text-align: center; margin: 0 0 2mm; }
  .ref { text-align: center; color: var(--muted); margin-bottom: 8mm; }
  h2 { color: var(--navy); font-size: 11.5pt; margin: 6mm 0 1.5mm; }
  table { width: 100%; border-collapse: collapse; margin: 2mm 0; }
  td, th { border: .3mm solid var(--line); padding: 1.5mm 2.5mm; vertical-align: top; text-align: left; }
  th { background: #e8edf6; width: 38%; font-weight: 600; }
  .signs { display: flex; justify-content: space-between; margin-top: 14mm; }
  .signs > div { width: 45%; text-align: center; }
  .signs .line { border-top: .3mm solid var(--ink); margin-top: 20mm; padding-top: 1mm; }
  .head { display: flex; justify-content: space-between; border-bottom: .6mm solid var(--navy); padding-bottom: 2mm; margin-bottom: 8mm; font-size: 9.5pt; color: var(--muted); }
  .head b { color: var(--navy); font-size: 11pt; }
@endsection
@section('content')
<div class="sheet">
  <div class="head"><div><b>HUMANITÉ ET RÉSILIENCE</b><br>Goma, Nord-Kivu, République Démocratique du Congo</div><div>Réf. {{ $contract->number }}</div></div>
  <h1>CONTRAT DE TRAVAIL {{ $contract->type->value === 'CDI' ? 'À DURÉE INDÉTERMINÉE' : ($contract->type->value === 'CDD' ? 'À DURÉE DÉTERMINÉE' : mb_strtoupper($contract->type->getLabel())) }}</h1>
  <div class="ref">Conclu conformément au Code du travail de la République Démocratique du Congo</div>

  <p>Entre <b>Humanité et Résilience</b>, organisation non gouvernementale ayant son siège à Goma, représentée par sa Direction Exécutive, ci-après « l'Organisation »,</p>
  <p>et <b>{{ $e->full_name }}</b>, né(e) le {{ $fmt($e->birth_date) }}@if ($e->birth_place) à {{ $e->birth_place }}@endif, de nationalité {{ $e->nationality }}, matricule {{ $e->matricule }}, ci-après « l'Employé(e) »,</p>
  <p>il est convenu ce qui suit.</p>

  <h2>Article 1 — Engagement et fonction</h2>
  <table>
    <tr><th>Poste</th><td>{{ $contract->position?->title ?? '—' }}@if ($contract->position?->department) ({{ $contract->position->department->name }})@endif</td></tr>
    <tr><th>Lieu d'affectation</th><td>{{ $contract->dutyStation?->name ?? '—' }}</td></tr>
    <tr><th>Supérieur hiérarchique direct</th><td>{{ $e->supervisor?->full_name ?? 'Direction Exécutive' }}</td></tr>
    <tr><th>Projet</th><td>{{ $contract->project ? $contract->project->code.' — '.$contract->project->title : '—' }}</td></tr>
  </table>
  <p>L'Employé(e) exerce les tâches décrites dans les termes de référence du poste, qu'il (elle) déclare avoir reçus et acceptés.</p>

  <h2>Article 2 — Durée et période d'essai</h2>
  <p>
  @if ($terms['end_date'])
    Le contrat prend effet le {{ $fmt($contract->start_date) }} et prend fin le {{ $fmt($terms['end_date']) }}, sans reconduction tacite.
  @else
    Le contrat prend effet le {{ $fmt($contract->start_date) }} pour une durée indéterminée.
  @endif
  @if ($contract->project)
    Sa durée ne peut excéder celle du projet qui le finance.
  @endif
  </p>
  @if ($terms['probation_end_date'])
  <p>Une période d'essai court jusqu'au {{ $fmt($terms['probation_end_date']) }}. Pendant celle-ci, chaque partie peut mettre fin au contrat avec un préavis de deux semaines. Elle se conclut par une évaluation et une décision de confirmation, de prolongation ou de fin du contrat.</p>
  @endif

  <h2>Article 3 — Rémunération</h2>
  <p>L'Employé(e) perçoit un salaire de base brut mensuel de <b>{{ Ui::money($terms['base_salary'], $contract->currency) }}</b>@if ($contract->salary_category) (catégorie {{ $contract->salary_category }}, étape {{ $contract->salary_step }})@endif, versé le 30 de chaque mois sur son compte bancaire, après les retenues légales.</p>

  <h2>Article 4 — Durée du travail et congés</h2>
  <p>La durée du travail est de {{ $contract->weekly_hours }} heures par semaine, du lundi au vendredi. L'Employé(e) acquiert 1,5 jour ouvrable de congé payé par mois de service, majoré d'un jour par tranche de cinq ans d'ancienneté, selon la politique et le manuel des procédures RH.</p>

  <h2>Article 5 — Obligations</h2>
  <p>L'Employé(e) respecte le Code de conduite, la politique de protection contre l'exploitation et les abus sexuels (bulletin ST/SGB/2003/13, partie intégrante du présent contrat), les politiques de lutte contre la fraude et la corruption, et observe une discrétion totale pendant le contrat et trois ans après son terme.</p>

  <h2>Article 6 — Fin du contrat et juridiction</h2>
  <p>Le contrat prend fin à son terme, par démission, par licenciement dans le respect de la procédure disciplinaire, ou pour restructuration, avec les préavis et indemnités prévus par la loi. Tout litige relève des juridictions de la République Démocratique du Congo.</p>

  @if ($contract->amendments->isNotEmpty())
  <h2>Avenants</h2>
  <table>
    @foreach ($contract->amendments as $a)
      <tr><th>{{ $a->kind->getLabel() }} — effet au {{ $a->effective_date->format('d/m/Y') }}</th><td>{{ $a->reason }}</td></tr>
    @endforeach
  </table>
  @endif

  <p style="margin-top:8mm">Fait à Goma, le {{ $fmt($contract->signed_at ?? now()) }}, en trois exemplaires originaux, dont un remis à l'Employé(e).</p>
  <div class="signs">
    <div><div class="line">L'Employé(e)<br>« Lu et approuvé »</div></div>
    <div><div class="line">Pour Humanité et Résilience<br>La Direction Exécutive</div></div>
  </div>
</div>
@endsection
