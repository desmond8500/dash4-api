<?php

namespace Tests\Support;

/**
 * Colonnes des tables Dash3 lues par la reprise (relevées sur l'export de production du 19/11/2026).
 * i = entier, f = décimal, s = texte.
 */
final class Dash3Schema
{
    public const TABLES = [
        'clients' => ['id' => 'i', 'name' => 's', 'description' => 's', 'address' => 's', 'avatar' => 's', 'status' => 'i', 'type' => 's', 'deleted_at' => 's', 'created_at' => 's', 'updated_at' => 's', 'favorite' => 'i'],
        'contacts' => ['id' => 'i', 'genre' => 's', 'firstname' => 's', 'lastname' => 's', 'deleted_at' => 's', 'created_at' => 's', 'updated_at' => 's', 'client_id' => 'i', 'projet_id' => 'i', 'avatar' => 's', 'fonction' => 's', 'phone' => 's', 'email' => 's', 'societe' => 's'],
        'brands' => ['id' => 'i', 'name' => 's', 'description' => 's', 'logo' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'providers' => ['id' => 'i', 'name' => 's', 'logo' => 's', 'address' => 's', 'description' => 's', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'email' => 's', 'phone' => 'i', 'website' => 's', 'latitude' => 'f', 'longitude' => 'f'],
        'provider_links' => ['id' => 'i', 'provider_id' => 'i', 'name' => 's', 'url' => 's', 'description' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'provider_documents' => ['id' => 'i', 'provider_id' => 'i', 'name' => 's', 'category' => 's', 'path' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'articles' => ['id' => 'i', 'designation' => 's', 'image' => 's', 'reference' => 's', 'description' => 's', 'quantity' => 'i', 'quantity_min' => 'i', 'priority_id' => 'i', 'brand_id' => 'i', 'provider_id' => 'i', 'price' => 'f', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'technique' => 's', 'spec' => 's', 'weight' => 'f', 'tva' => 'f'],
        'article_dependences' => ['id' => 'i', 'article_id' => 'i', 'dependence_id' => 'i', 'quantity' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'article_links' => ['id' => 'i', 'article_id' => 'i', 'name' => 's', 'link' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'article_documents' => ['id' => 'i', 'article_id' => 'i', 'name' => 's', 'folder' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'projets' => ['id' => 'i', 'client_id' => 'i', 'logo' => 's', 'name' => 's', 'description' => 's', 'start_date' => 's', 'end_date' => 's', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'favorite' => 'i', 'address' => 's', 'latitude' => 'f', 'longitude' => 'f'],
        'projet_notes' => ['id' => 'i', 'projet_id' => 'i', 'titre' => 's', 'description' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'project_docs' => ['id' => 'i', 'projet_id' => 'i', 'document_name' => 's', 'document_path' => 's', 'category' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'client_notes' => ['id' => 'i', 'client_id' => 'i', 'description' => 's', 'note' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'brand_notes' => ['id' => 'i', 'brand_id' => 'i', 'title' => 's', 'description' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'brand_links' => ['id' => 'i', 'brand_id' => 'i', 'name' => 's', 'link' => 's', 'folder' => 's', 'description' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'buildings' => ['id' => 'i', 'projet_id' => 'i', 'name' => 's', 'order' => 'i', 'description' => 's', 'deleted_at' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'building_documents' => ['id' => 'i', 'building_id' => 'i', 'name' => 's', 'folder' => 's', 'link' => 's', 'description' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'stages' => ['id' => 'i', 'building_id' => 'i', 'name' => 's', 'order' => 'i', 'description' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'rooms' => ['id' => 'i', 'stage_id' => 'i', 'name' => 's', 'order' => 'i', 'description' => 's', 'deleted_at' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'systemes' => ['id' => 'i', 'name' => 's', 'description' => 's', 'deleted_at' => 's', 'created_at' => 's', 'updated_at' => 's', 'icon' => 's'],
        'installation_systemes' => ['id' => 'i', 'projet_id' => 'i', 'building_id' => 'i', 'systeme_id' => 'i', 'name' => 's', 'description' => 's', 'order' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'equipements' => ['id' => 'i', 'installation_systeme_id' => 'i', 'parent_id' => 'i', 'article_id' => 'i', 'stage_id' => 'i', 'room_id' => 'i', 'name' => 's', 'type' => 's', 'modele' => 's', 'quantite' => 'i', 'caracteristiques' => 's', 'description' => 's', 'order' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'installations' => ['id' => 'i', 'projet_id' => 'i', 'description' => 's', 'type' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'objets' => ['id' => 'i', 'installation_id' => 'i', 'parent_id' => 'i', 'article_id' => 'i', 'name' => 's', 'description' => 's', 'type' => 's', 'attributs' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'attributs' => ['id' => 'i', 'objet_id' => 'i', 'name' => 's', 'value' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'plans' => ['id' => 'i', 'projet_id' => 'i', 'building_id' => 'i', 'name' => 's', 'file' => 's', 'converted_file' => 's', 'conversion_status' => 's', 'conversion_error' => 's', 'mime' => 's', 'kind' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'plan_markers' => ['id' => 'i', 'plan_id' => 'i', 'equipement_id' => 'i', 'page' => 'i', 'x' => 'f', 'y' => 'f', 'label' => 's', 'icon' => 's', 'color' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'fiches' => ['id' => 'i', 'building_id' => 'i', 'titre' => 's', 'date' => 's', 'phone' => 's', 'email' => 's', 'type' => 's', 'client' => 's', 'created_at' => 's', 'updated_at' => 's', 'user_id' => 'i', 'systeme' => 's'],
        'fiche_zones' => ['id' => 'i', 'fiche_id' => 'i', 'order' => 'i', 'equipement' => 's', 'name' => 's', 'number' => 's', 'code' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'invoice_systems' => ['id' => 'i', 'name' => 's', 'description' => 's', 'icon' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'invoice_models' => ['id' => 'i', 'invoice_system_id' => 'i', 'name' => 's', 'description' => 's', 'icon' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'invoice_model_rows' => ['id' => 'i', 'invoice_model_id' => 'i', 'article_id' => 'i', 'designation' => 's', 'description' => 's', 'coef' => 'f', 'reference' => 's', 'quantite' => 'i', 'prix' => 'f', 'priorite_id' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'invoices' => ['id' => 'i', 'invoice_number' => 'i', 'invoice_year' => 'i', 'projet_id' => 'i', 'client_name' => 's', 'projet_name' => 's', 'reference' => 's', 'description' => 's', 'modalite' => 's', 'note' => 's', 'statut' => 's', 'tax' => 's', 'remise' => 'f', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'favorite' => 'i', 'start_date' => 's', 'end_date' => 's', 'facture_date' => 's', 'paydate' => 's', 'comptabilise' => 'i', 'emitdate' => 's'],
        'invoice_sections' => ['id' => 'i', 'invoice_id' => 'i', 'section' => 's', 'ordre' => 'i', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'proposition' => 's', 'status' => 'i', 'show' => 'i'],
        'invoice_rows' => ['id' => 'i', 'invoice_section_id' => 'i', 'designation' => 's', 'coef' => 'f', 'reference' => 's', 'quantite' => 'i', 'prix' => 'f', 'priorite_id' => 'i', 'article_id' => 'i', 'comment' => 's', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'bought' => 'i'],
        'invoice_acomptes' => ['id' => 'i', 'invoice_id' => 'i', 'name' => 's', 'description' => 's', 'montant' => 's', 'statut' => 'i', 'date' => 's', 'deleted_at' => 's', 'created_at' => 's', 'updated_at' => 's', 'show' => 'i'],
        'invoice_spents' => ['id' => 'i', 'invoice_id' => 'i', 'name' => 's', 'description' => 's', 'montant' => 's', 'file' => 's', 'date' => 's', 'deleted_at' => 's', 'created_at' => 's', 'updated_at' => 's', 'status' => 's'],
        'invoice_bls' => ['id' => 'i', 'invoice_id' => 'i', 'name' => 's', 'date' => 's', 'type' => 's', 'done' => 's', 'todo' => 's', 'created_at' => 's', 'updated_at' => 's', 'logo' => 'i'],
        'invoice_documents' => ['id' => 'i', 'invoice_id' => 'i', 'name' => 's', 'type' => 's', 'file' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'factures' => ['id' => 'i', 'invoice_id' => 'i', 'folder' => 's', 'status' => 's', 'reference' => 's', 'description' => 's', 'montant' => 'i', 'date' => 's', 'year' => 'i', 'month' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'forfaits' => ['id' => 'i', 'designation' => 's', 'description' => 's', 'price' => 'i', 'created_at' => 's', 'updated_at' => 's', 'client_id' => 'i', 'article_id' => 'i'],
        'achats' => ['id' => 'i', 'provider_id' => 'i', 'name' => 's', 'description' => 's', 'date' => 's', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'transaction_id' => 'i', 'status' => 's', 'journal_id' => 'i', 'remise' => 'f'],
        'achat_rows' => ['id' => 'i', 'achat_id' => 'i', 'designation' => 's', 'reference' => 's', 'quantite' => 'i', 'prix' => 'f', 'tva' => 'f', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'article_id' => 'i'],
        'achat_factures' => ['id' => 'i', 'achat_id' => 'i', 'folder' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'transactions' => ['id' => 'i', 'achat_id' => 'i', 'projet_id' => 'i', 'devis_id' => 'i', 'objet' => 's', 'type' => 's', 'description' => 's', 'montant' => 'i', 'date' => 's', 'created_at' => 's', 'updated_at' => 's', 'invoice_acompte_id' => 's', 'invoice_spent_id' => 's', 'invoice_id' => 'i'],
        'tasks' => ['id' => 'i', 'client_id' => 'i', 'projet_id' => 'i', 'devis_id' => 'i', 'building_id' => 'i', 'stage_id' => 'i', 'room_id' => 'i', 'name' => 's', 'description' => 's', 'expiration_date' => 's', 'priority_id' => 'i', 'statut_id' => 'i', 'favoris' => 'i', 'created_at' => 's', 'updated_at' => 's', 'start_date' => 's', 'end_date' => 's', 'journal_id' => 'i', 'journal_section_id' => 'i', 'myproject_id' => 'i'],
        'teams' => ['id' => 'i', 'firstname' => 's', 'lastname' => 's', 'function' => 's', 'created_at' => 's', 'updated_at' => 's'],
        'journals' => ['id' => 'i', 'user_id' => 'i', 'client_id' => 'i', 'projet_id' => 'i', 'devis_id' => 'i', 'title' => 's', 'date' => 's', 'description' => 's', 'created_at' => 's', 'updated_at' => 's', 'deleted_at' => 's', 'type' => 's'],
        'journal_sections' => ['id' => 'i', 'journal_id' => 'i', 'name' => 's', 'description' => 's', 'order' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'journal_intervenants' => ['id' => 'i', 'journal_id' => 'i', 'contact_id' => 'i', 'team_id' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'c_v_s' => ['id' => 'i', 'user_id' => 'i', 'name' => 's', 'title' => 's', 'fullname' => 's', 'summary' => 's', 'photo' => 's', 'phone' => 's', 'email' => 's', 'address' => 's', 'website' => 's', 'accent_color' => 's', 'font' => 's', 'layout' => 's', 'show_photo' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'cv_sections' => ['id' => 'i', 'c_v_id' => 'i', 'name' => 's', 'type' => 's', 'order' => 'i', 'created_at' => 's', 'updated_at' => 's'],
        'cv_items' => ['id' => 'i', 'cv_section_id' => 'i', 'title' => 's', 'subtitle' => 's', 'place' => 's', 'start_date' => 's', 'end_date' => 's', 'current' => 'i', 'description' => 's', 'level' => 'i', 'order' => 'i', 'created_at' => 's', 'updated_at' => 's'],
    ];
}
