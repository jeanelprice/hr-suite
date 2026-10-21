<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Données de référence indispensables, chargées aussi sur une base existante :
 * types de congé du Manuel des procédures (§5.10, §3.2) et de la Politique RH (§5.3),
 * jours fériés légaux de la RDC pour 2026 et 2027 (modifiables par le RH).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $types = [
            // code, libellé, décompté, solde propre, taux de salaire, jours calendaires, max, justificatif dès, préavis, approbateur, ancienneté min (mois), hors essai, libre-service, sexe, règle
            ['ANNUEL', 'Congé annuel', true, false, 1, false, null, null, 30, 'RH', null, true, true, null,
                "1,5 jour ouvrable par mois de service, plus 1 jour par tranche de 5 ans d'ancienneté (24 jours au plus). Pas de congé annuel pendant la période d'essai. Demande au moins un mois avant le départ."],
            ['MALADIE', 'Congé de maladie', false, false, 1, false, null, 2, null, 'RH', null, false, true, null,
                "Prévenir le supérieur dès que possible. Certificat médical obligatoire dès 2 jours consécutifs. Au-delà de 14 jours ouvrables, la Direction Exécutive est informée."],
            ['MATERNITE', 'Congé de maternité', false, false, 0.6667, true, 98, null, null, 'RH', null, false, true, 'F',
                "14 semaines, dont 6 avant la date prévue de l'accouchement ; 2/3 du salaire maintenus. Prolonge la période d'essai d'autant."],
            ['PATERNITE', 'Congé de paternité', false, false, 1, false, null, null, 30, 'RH', null, false, true, 'M',
                'Durée légale à paramétrer par le RH. Préavis d\'un mois lorsque la date est prévisible.'],
            ['COMPASSION', 'Congé de compassion', false, false, 1, false, 4, null, null, 'DE', null, false, true, null,
                "Jusqu'à 4 jours ouvrables pour un deuil dans la famille immédiate (conjoint, enfant, parent, frère ou sœur)."],
            ['ETUDES', "Congé pour raisons d'études", false, false, 0, false, null, null, 30, 'DE', 12, true, true, null,
                "Non rémunéré. Réservé aux employés confirmés ayant plus d'un an de service. Demande écrite avec justificatifs un mois avant."],
            ['NON_PAYE', 'Congé non payé', false, false, 0, false, null, null, null, 'DE', null, false, true, null,
                'Au cas par cas, autorisé au préalable par la Direction Exécutive. Salaire retenu au prorata.'],
            ['RECUPERATION', 'Récupération', false, true, 1, false, null, null, null, 'RH', null, false, true, null,
                "Jours accordés pour un travail un jour férié ou en heures longues ; décomptés de leur propre solde."],
            ['ABSENCE', 'Absence non autorisée', false, false, 0, false, null, null, null, 'RH', null, false, false, null,
                'Saisie par le RH. Salaire retenu au prorata ; deux jours consécutifs alertent le RH et la DE (Manuel §3.2).'],
        ];
        foreach ($types as $i => $t) {
            DB::table('leave_types')->insertOrIgnore([
                'code' => $t[0], 'label' => $t[1], 'deducts_balance' => $t[2], 'own_balance' => $t[3], 'pay_rate' => $t[4],
                'calendar_days' => $t[5], 'max_days' => $t[6], 'document_after_days' => $t[7], 'notice_days' => $t[8],
                'final_approver' => $t[9], 'min_seniority_months' => $t[10], 'requires_confirmation' => $t[11],
                'self_service' => $t[12], 'gender' => $t[13], 'rule' => $t[14], 'is_active' => true, 'sort' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $holidays = [
            '01-01' => "Nouvel An", '01-04' => "Martyrs de l'indépendance", '01-16' => 'Laurent-Désiré Kabila',
            '01-17' => 'Patrice Emery Lumumba', '04-06' => 'Simon Kimbangu', '05-01' => 'Fête du travail',
            '05-17' => 'Libération et Forces armées', '06-30' => 'Indépendance', '08-01' => 'Fête des parents',
            '08-02' => 'Génocide congolais (Genocost)', '12-25' => 'Noël',
        ];
        foreach ([2026, 2027] as $year) {
            foreach ($holidays as $md => $label) {
                DB::table('public_holidays')->insertOrIgnore(['date' => "{$year}-{$md}", 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('public_holidays')->delete();
        DB::table('leave_types')->delete();
    }
};
