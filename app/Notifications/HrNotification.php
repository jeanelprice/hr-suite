<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fondation A — Notification générique : email + cloche de l'application.
 * Les modèles de messages sont définis par les constructeurs statiques ci-dessous.
 */
class HrNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $subject,
        public readonly array $lines,
        public readonly string $status = 'info', // info, success, warning, danger
        public readonly ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('[HR Suite] '.$this->subject)->greeting('Bonjour,');
        foreach (array_filter($this->lines) as $line) {
            $mail->line($line);
        }
        if ($this->url) {
            $mail->action('Ouvrir dans HR Suite', $this->url);
        }

        return $mail->salutation('— HR Suite, Humanité et Résilience');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->subject)
            ->body(implode("\n", array_filter($this->lines)))
            ->status($this->status)
            ->getDatabaseMessage();
    }

    // ------------------------------------------------------------------ modèles

    /** RG-08 : opération bloquée pour dépassement (ou dérogation demandée) */
    public static function budgetExceeded(array $info, string $authorName, ?string $request = null): self
    {
        return new self(
            "Opération bloquée — ligne budgétaire {$info['line_code']} insuffisante",
            array_values(array_filter([
                "Opération : {$info['operation']}",
                "Saisie par : {$authorName}",
                "Projet : {$info['project_code']} — Ligne : {$info['line_code']} {$info['line_label']}",
                "Montant demandé : {$info['requested']} {$info['currency']} — Solde disponible : {$info['available']} {$info['currency']}",
                $request ? "Dérogation demandée : {$request}" : null,
                "Aucun dépassement n'est possible sans dérogation de la Direction Exécutive (RG-09).",
            ])),
            'danger',
        );
    }

    public static function validationRequested(string $documentLabel, string $authorName, string $summary, \DateTimeInterface $dueAt, ?string $url = null): self
    {
        return new self(
            "Validation demandée — {$documentLabel}",
            ["{$authorName} a soumis {$documentLabel} pour validation.", $summary, 'Échéance de traitement : '.$dueAt->format('d/m/Y H:i').'.'],
            'warning',
            $url,
        );
    }

    public static function decision(string $documentLabel, string $decision, string $deciderName, ?string $comment = null, ?string $url = null): self
    {
        return new self(
            ucfirst($documentLabel)." : {$decision}",
            array_values(array_filter([ucfirst($documentLabel)." a été {$decision} par {$deciderName}.", $comment ? "Commentaire : {$comment}" : null])),
            str_starts_with($decision, 'rejet') ? 'danger' : 'success',
            $url,
        );
    }

    public static function derogationGranted(string $amount, string $currency, string $line, string $project, string $justification, \DateTimeInterface $regularizeBy): self
    {
        return new self(
            "Dérogation budgétaire accordée — ligne {$line}",
            [
                "La Direction Exécutive a accordé une dérogation de {$amount} {$currency} sur la ligne {$line} (projet {$project}).",
                "Justification : {$justification}",
                'Une révision budgétaire de régularisation doit être validée avant le '.$regularizeBy->format('d/m/Y').'.',
            ],
            'warning',
        );
    }
}
