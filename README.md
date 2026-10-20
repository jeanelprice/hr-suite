# HR Suite — Humanité et Résilience

ERP de l'ONG **Humanité et Résilience** (Goma, Nord-Kivu), construit avec **Laravel 13**
et **Filament 5**. Cette première version couvre la **Phase 1 Finances & Achats** du cahier des
charges v1.1 : projets, budgets versionnés, demandes d'achat, bons de commande, paiements,
dérogations, comptes et habilitations, journal d'audit — et l'**étape 1 du module Personnel** :
dossier du personnel avec photo, contrats, avenants, période d'essai, carte de service,
discipline, sortie, organigramme et pièces jointes.

## Installer sur un ordinateur Windows (sans Composer, sans internet)

Prérequis : **Laravel Herd** installé (https://herd.laravel.com).

1. Sur la page du dépôt GitHub, ouvrez **Releases** → **HR Suite — dernière version**,
   puis téléchargez **`hr-suite-pret-a-l-emploi.zip`**.
2. Si un ancien dossier `C:\Users\<vous>\Herd\hr-suite` existe, renommez-le (par exemple `hr-suite-ancien`).
3. Décompressez le ZIP dans `C:\Users\<vous>\Herd` : vous obtenez le dossier `Herd\hr-suite`.
4. Ouvrez PowerShell et tapez :
   ```
   cd $env:USERPROFILE\Herd\hr-suite
   php artisan hr:installer
   ```
5. Ouvrez **http://hr-suite.test** et connectez-vous avec un compte de démonstration.

| Rôle | Email | Mot de passe |
|---|---|---|
| Direction Exécutive | direction@humaniteresilience.org | ChangezMoi123! |
| DE adjointe (suppléante) | adjointe@humaniteresilience.org | ChangezMoi123! |
| Financier | financier@humaniteresilience.org | ChangezMoi123! |
| Chef de projet | chef.projet@humaniteresilience.org | ChangezMoi123! |
| Comité de Contrôle | controle@humaniteresilience.org | ChangezMoi123! |
| Responsable RH | rh@humaniteresilience.org | ChangezMoi123! |

Pour repartir d'une base de démonstration neuve (tout est effacé) : `php artisan hr:installer --force`.
Pour une base sans données de démonstration : `php artisan hr:installer --force --sans-demo`.

## Mettre à jour sans perdre vos données

À chaque nouvelle version, vos données restent dans trois éléments à conserver : le fichier
`.env`, la base `database\database.sqlite` et le dossier `storage\app\private` (photos et documents).

1. Téléchargez le nouveau `hr-suite-pret-a-l-emploi.zip` (Releases → HR Suite — dernière version).
2. Dans PowerShell :
   ```
   cd $env:USERPROFILE\Herd
   Rename-Item hr-suite hr-suite-ancien
   Expand-Archive $env:USERPROFILE\Downloads\hr-suite-pret-a-l-emploi.zip -DestinationPath .
   Copy-Item hr-suite-ancien\.env hr-suite\
   Copy-Item hr-suite-ancien\database\database.sqlite hr-suite\database\
   if (Test-Path hr-suite-ancien\storage\app\private) { Copy-Item hr-suite-ancien\storage\app\private hr-suite\storage\app\ -Recurse -Force }
   cd hr-suite
   php artisan hr:installer
   ```
3. `hr:installer` voit la base existante : il ajoute les nouvelles tables sans rien effacer. Sur une
   base de démonstration, il ajoute aussi la démonstration du personnel et le compte RH.
4. Quand tout fonctionne, supprimez le dossier `hr-suite-ancien`.

## Essayer le module Personnel

Connectez-vous avec le compte **Responsable RH**.

1. *Personnel → Employés → Nouvel employé* : photo (recadrée au carré), identité, pièce,
   coordonnées, urgence, banque, situation. Le matricule (HR-0001…) est attribué automatiquement.
2. Dans le dossier : **Nouveau contrat** (prérempli), ventilation sur les lignes budgétaires (100 %),
   puis **Imprimer** et, après signature, **Contrat signé**. Le contrat devient actif.
3. Sur un contrat actif : **Avenant** (salaire, poste, lieu, prolongation) à sa date d'effet.
   La **Décision de fin d'essai** est réservée à la Direction Exécutive.
4. Dans le dossier : **Carte de service** (délivrer, imprimer, perte, restitution), onglets
   Documents, Famille, Diplômes, Liens déclarés, Discipline, Historique ; **Sortie** puis
   **Certificat d'autorisation**.
5. *Personnel → Organigramme* ; l'accueil affiche les **échéances du personnel** (fins de contrat
   à 30 jours, fins d'essai à 15 jours).

Les alertes par email de fin de contrat et de fin d'essai partent avec `php artisan hr:echeances`,
prévu chaque matin à 7 h par le planificateur de Laravel. Sur un poste Windows sans planificateur,
lancez la commande à la main ou via le Planificateur de tâches Windows.

Les documents joints acceptent 10 Mo par fichier. Si un dépôt plus lourd échoue, augmentez
`upload_max_filesize` et `post_max_size` dans les réglages PHP de Herd.

## Essayer le circuit complet

1. **Chef de projet** : *Achats → Demandes d'achat → Nouvelle demande*. Le solde de chaque ligne
   s'affiche ; une demande qui le dépasse est bloquée et une alerte part aux responsables.
2. Ouvrez la demande → **Soumettre pour validation**.
3. **Direction Exécutive** : **Valider** → le montant est pré-engagé (visible dans *Finances → Budgets → Tableau de bord*).
4. **Chef de projet** : **Établir le bon de commande** → **Soumettre** ; **DE** : **Valider** → engagement.
5. **Financier** : sur le bon de commande, **Enregistrer un paiement** ; **DE** : *Paiements* → **Approuver**.
6. **Financier** : **Clôturer** le bon de commande → le reliquat retourne au solde.
7. **Comité de Contrôle** : *Administration → Journal d'audit* → **Vérifier l'intégrité**.

## Règles du cahier des charges et où elles sont codées

| Règle | Fichier |
|---|---|
| RG-01 solde, RG-07 blocage, RG-08 alerte, RG-09 dérogation | `app/Services/BudgetLedger.php` |
| RG-02 pré-engagement, annulation | `app/Services/PurchaseRequestService.php` |
| RG-03 engagement, RG-05 reliquat | `app/Services/PurchaseOrderService.php` |
| RG-04 plafond, RG-15 double signature, RG-16 trésorerie, écart de change | `app/Services/PaymentService.php` |
| RG-10 révisions et archivage, RG-13 activation du projet | `app/Services/BudgetService.php` |
| RG-11 devises | `app/Services/CurrencyService.php` |
| RG-14 ségrégation, suppléance, délégation | `app/Services/ApprovalService.php` |
| RG-20 audit chaîné et inaltérable | `app/Services/AuditService.php` + migration `protect_audit_and_ledger` |
| Fondation A (emails + cloche) | `app/Notifications/HrNotification.php`, `app/Services/NotificationService.php` |
| Fondation C (habilitations par module) | `app/Models/User.php`, `app/Filament/Concerns/HasModuleAccess.php` |
| Fondation B (pièces jointes versionnées, accès contrôlé) | `app/Services/AttachmentService.php`, `app/Http/Controllers/FileController.php` |
| RG-22 contrats, avenants, ventilation, période d'essai | `app/Services/ContractService.php` |
| Dossier, hiérarchie, carte, discipline, sortie, RG-27 liens de parenté | `app/Services/EmployeeService.php` |
| RG-26 données personnelles (qui voit quoi) | `app/Support/PersonnelAccess.php` |
| RG-19 échéances du personnel | `app/Console/Commands/PersonnelDeadlinesCommand.php` |

Les écrans sont dans `app/Filament/Resources`, un dossier par module. Ils n'appliquent aucune
règle eux-mêmes : ils appellent les services ci-dessus.

## Tests automatiques

```
php artisan test
```

31 tests : les règles métier (blocage, alertes, pré-engagement, conversions USD/CDF, paiements
partiels, clôture, suppléance, dérogation, révision et archivage, audit inaltérable ; contrats,
avenants, période d'essai, sortie, discipline, liens de parenté, données chiffrées, accès aux
documents, alertes d'échéance) et les écrans (chaque page s'ouvre ; parcours achat, paiement,
budget, habilitations, dossier avec photo, contrat signé, avenant, carte et impressions). À chaque mise à
jour, GitHub relance ces tests et publie un nouveau ZIP dans **Releases** s'ils réussissent.

## Paramètres modifiables

Valeurs par défaut dans `app/Models/Setting.php` : seuil d'alerte de consommation (80 %),
délai de régularisation d'une dérogation (30 jours), délai de validation (72 heures).

## Emails

En local, les emails sont écrits dans `storage/logs/laravel.log` (`MAIL_MAILER=log`), et chaque
notification apparaît aussi dans la cloche de l'application. Pour de vrais envois, renseignez
`MAIL_MAILER=smtp` et les paramètres SMTP dans le fichier `.env`.

## Ce qui reste à faire

- Module Personnel, étapes 2 à 5 : congés et absences avec l'espace personnel, recrutement,
  évaluations, paie (voir « Modèle de données du module Personnel »).
- Pièces jointes sur les achats (factures, proformas) avec le composant de la Fondation B.
- Fondation D — rappels et escalades des validations (les échéances du personnel sont faites).
- Double authentification pour la DE, le Financier et l'administrateur.
