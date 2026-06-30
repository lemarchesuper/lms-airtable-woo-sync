=== LMS Airtable Sync ===
Contributors: lemarchesuper
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0

Synchronisation descendante (pull) Airtable → WooCommerce pour Le Marché Super.

== Description ==

Récupère les produits depuis Airtable (base « Odoo Products », table « WEB | Products »,
vue « To Import WC ») et crée / met à jour les produits WooCommerce correspondants.

Principes :
* Sens UNIQUE Airtable → WooCommerce. Rien n'est jamais renvoyé vers Airtable.
* Matching par un meta_key dédié contenant le Record ID Airtable (ex. _airtable_record_id).
* Les IMAGES ne sont jamais touchées (gérées manuellement dans WooCommerce).
* Aucune SUPPRESSION de produit (politique « absent » configurable : ignorer / rupture / brouillon).
* Mapping piloté par fichier, surchargeable via le filtre `lms_ats_field_map`.

== Installation ==

1. Copier le dossier `lms-airtable-sync/` dans `wp-content/plugins/`.
2. Activer le plugin (WooCommerce doit être actif).
3. WooCommerce → Airtable Sync : saisir le Token API Airtable (PAT, scope data.records:read).
4. Vérifier Base ID / Table ID / Vue (pré-remplis).
5. Cocher « Mode simulation » puis « Synchroniser maintenant » pour un essai à blanc.
6. Lire le journal, décocher la simulation, relancer.

== Configuration du cron fiable (o2switch) ==

WP-Cron dépend du trafic. Pour un déclenchement régulier, désactiver WP-Cron interne
et ajouter un cron cPanel, par exemple toutes les 15 minutes :

    */15 * * * * wget -q -O /dev/null "https://lemarchesuper.com/wp-cron.php?doing_wp_cron"

== À faire avant la mise en production ==

* Remplacer les meta_key `TODO_*` dans `includes/class-lms-ats-field-map.php`
  par les vrais noms écrits aujourd'hui par WP All Import (conditionnement, consigne,
  attributs, EAN, producteur). Tant qu'une clé reste `TODO_*`, sa valeur n'est PAS écrite.
* Confirmer le champ prix : « WC | Net Sale Price » vs « WC | Consigned Sale Price ».
* Confirmer le traitement du producteur (taxonomie vs meta) — stocké provisoirement
  dans le meta `_lms_producteur_wc_id`.

== Changelog ==

= 0.5.0 =
* Auto-réparation : un produit présent dans la vue est (re)publié. Un produit passé
  en brouillon par erreur (politique absent sur une vue partielle) est republié dès
  qu'il réapparaît dans la vue synchronisée.
* La détection « inchangé » exige désormais aussi le statut publié : un produit en
  brouillon mais inchangé n'est plus sauté, il est republié.
* Permet de récupérer un catalogue passé en brouillon par erreur : pointer sur la
  vue complète et synchroniser.

= 0.4.0 =
* Politique « produit absent » optimisée : n'agit que sur les produits PAS DÉJÀ
  dans l'état cible (ex. déjà en brouillon → ignoré). Évite de re-traiter tout le
  lot d'absents à chaque run.
* Journal : message « N absent(s) au total, M nouvellement traité(s) », visible aussi
  en mode simulation pour estimer l'impact avant d'activer draft/outofstock.
* Rappel : la politique s'applique à tous les produits gérés absents de la vue —
  ne l'activer (draft/outofstock) qu'avec la vue COMPLÈTE, jamais une vue partielle.

= 0.3.0 =
* Détection de changement par empreinte (hash) : seuls les produits réellement
  modifiés sont réécrits. Sur un run complet, les centaines de produits inchangés
  sont sautés (aucune écriture, aucun update_field). Empreinte stockée dans le
  meta _airtable_sync_hash.
* Journal : nouvelle colonne « Inchangés » (rapport de ce qui a bougé).
* 1er run complet = baseline (tout est tamponné), runs suivants = seuls les modifiés.

= 0.2.0 =
* Champs custom = ACF : écriture via update_field() (pose la référence de clé, vital en création).
* Mapping réel relevé en live (REST produit 5184) : SKU=EAN13, prix Consigned, net_price,
  consigne_price, product_package/price_unit/unit_price, product_displayed_name.
* Taxonomies pays/label/packaging. Catégories hiérarchiques.
* Fix champs de liaison Airtable : appel en cellFormat=string (liens/choix → noms, comme le CSV).
* Producteur et attributs WooCommerce reportés (hors scope V1).

= 0.1.0 =
* Version initiale : pull Airtable, upsert Woo (natifs + meta + catégories hiérarchiques),
  page de réglages, mode simulation, journal, cron, bouton de synchro manuelle.
