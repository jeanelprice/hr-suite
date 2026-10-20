@extends('print.layout')
@section('title', 'Carte de service '.$card->number)
@section('hint', 'Format carte bancaire (85,6 × 54 mm). Imprimez à 100 %, recto puis verso.')
@section('style')
  @page { size: A4; margin: 15mm; }
  .cards { display: flex; flex-wrap: wrap; gap: 10mm; padding: 10mm; width: 210mm; }
  .card { width: 85.6mm; height: 54mm; border: 0.3mm solid var(--line); border-radius: 3mm; overflow: hidden; position: relative; background: #fff; }
  .band { background: var(--navy); color: #fff; padding: 2mm 3mm; display: flex; justify-content: space-between; align-items: center; }
  .band b { font-size: 3.4mm; letter-spacing: .2mm; }
  .band span { font-size: 2.4mm; opacity: .85; }
  .body { display: flex; gap: 3mm; padding: 2.5mm 3mm; }
  .photo { width: 22mm; height: 27mm; border-radius: 1.5mm; object-fit: cover; background: #e3e7ef; display: flex; align-items: center; justify-content: center; font-size: 7mm; color: var(--navy); font-weight: 700; }
  .info { font-size: 2.6mm; line-height: 1.35; }
  .info .name { font-size: 3.3mm; font-weight: 700; color: var(--navy); margin-bottom: .8mm; }
  .info .label { color: var(--muted); }
  .foot { position: absolute; bottom: 0; left: 0; right: 0; display: flex; justify-content: space-between; padding: 1.5mm 3mm; font-size: 2.2mm; border-top: .2mm solid var(--line); }
  .back { padding: 4mm; font-size: 2.6mm; line-height: 1.45; }
  .back h4 { margin: 0 0 1.5mm; color: var(--navy); font-size: 3mm; }
  .sign { position: absolute; bottom: 4mm; right: 4mm; text-align: center; font-size: 2.3mm; color: var(--muted); }
  .sign .line { width: 30mm; border-top: .2mm solid var(--ink); margin-bottom: .8mm; }
@endsection
@section('content')
<div class="sheet cards">
  <div class="card">
    <div class="band"><b>HUMANITÉ ET RÉSILIENCE</b><span>Carte de service</span></div>
    <div class="body">
      @if ($employee->photo_path)
        <img class="photo" src="{{ route('files.photo', $employee) }}" alt="Photo">
      @else
        <div class="photo">{{ $employee->initials }}</div>
      @endif
      <div class="info">
        <div class="name">{{ $employee->full_name }}</div>
        <div><span class="label">Matricule :</span> {{ $employee->matricule }}</div>
        <div><span class="label">Fonction :</span> {{ $employee->position?->title ?? '—' }}</div>
        <div><span class="label">Affectation :</span> {{ $employee->dutyStation?->name ?? '—' }}</div>
      </div>
    </div>
    <div class="foot"><span>N° {{ $card->number }}</span><span>Délivrée le {{ $card->issued_at->format('d/m/Y') }}@if ($card->expires_at) · valable jusqu'au {{ $card->expires_at->format('d/m/Y') }}@endif</span></div>
  </div>
  <div class="card">
    <div class="back">
      <h4>Humanité et Résilience — Goma, Nord-Kivu</h4>
      Cette carte est la propriété de Humanité et Résilience. Elle est personnelle et doit être restituée à la fin du contrat, en cas de démission ou de licenciement.
      En cas de perte, le titulaire en informe immédiatement le service des ressources humaines ; le remplacement est à sa charge.
      Si vous trouvez cette carte, merci de la remettre au bureau de l'organisation.
    </div>
    <div class="sign"><div class="line"></div>La Direction Exécutive</div>
  </div>
</div>
@endsection
