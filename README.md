# HR Suite — Humanité et Résilience

ERP de l'ONG **Humanité et Résilience** (Goma, Nord-Kivu), construit avec **Laravel 13**
et **Filament 5**. Cette première version couvre la **Phase 1 Finances & Achats** du cahier des
charges v1.1 : projets, budgets versionnés, demandes d'achat, bons de commande, paiements,
dérogations, comptes et habilitations, journal d'audit.

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

Pour repartir d'une base de démonstration neuve (tout est effacé) : `php artisan hr:installer --force`.
Pour une base sans données de démonstration : `php artisan hr:installer --force --sans-demo`.

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

Les écrans sont dans `app/Filament/Resources`, un dossier par module. Ils n'appliquent aucune
règle eux-mêmes : ils appellent les services ci-dessus.

## Tests automatiques

```
php artisan test
```

18 tests : les règles métier (blocage, alertes, pré-engagement, conversions USD/CDF, paiements
partiels, clôture, suppléance, dérogation, révision et archivage, audit inaltérable) et les
écrans (chaque page s'ouvre ; parcours achat, paiement, budget, habilitations). À chaque mise à
jour, GitHub relance ces tests et publie un nouveau ZIP dans **Releases** s'ils réussissent.

## Paramètres modifiables

Valeurs par défaut dans `app/Models/Setting.php` : seuil d'alerte de consommation (80 %),
délai de régularisation d'une dérogation (30 jours), délai de validation (72 heures).

## Emails

En local, les emails sont écrits dans `storage/logs/laravel.log` (`MAIL_MAILER=log`), et chaque
notification apparaît aussi dans la cloche de l'application. Pour de vrais envois, renseignez
`MAIL_MAILER=smtp` et les paramètres SMTP dans le fichier `.env`.

## Ce qui reste à faire pour la Phase 1

- Fondation B — pièces jointes (factures, proformas, justifications).
- Fondation D — planificateur des rappels et des escalades (les échéances sont déjà enregistrées).
- Double authentification pour la DE, le Financier et l'administrateur.
- Module RH & Contrats.
