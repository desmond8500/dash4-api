<?php

use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AssigneeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientLogoController;
use App\Http\Controllers\Api\V1\ClientRequestController;
use App\Http\Controllers\Api\V1\ClientRequestConvertController;
use App\Http\Controllers\Api\V1\ClientRequestLinkController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DeliveryNoteController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EmailSignatureController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseSpaceController;
use App\Http\Controllers\Api\V1\InboundEmailController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectLogoController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\QuoteEmailController;
use App\Http\Controllers\Api\V1\QuoteLineController;
use App\Http\Controllers\Api\V1\QuoteMoneyController;
use App\Http\Controllers\Api\V1\QuoteSectionController;
use App\Http\Controllers\Api\V1\QuoteTemplateController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StockLogoController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StorageLocationController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\Attachable;
use Illuminate\Support\Facades\Route;

/*
| API Dash4 — version 1. Toutes les routes sont préfixées par /api/v1.
| Authentification : jeton Bearer (Laravel Sanctum).
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]))->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::apiResource('users', UserController::class)->except('destroy');

        Route::apiResource('clients', ClientController::class);
        Route::post('clients/{client}/logo', [ClientLogoController::class, 'store']);
        Route::delete('clients/{client}/logo', [ClientLogoController::class, 'destroy']);
        Route::apiResource('contacts', ContactController::class);

        Route::get('client-requests/summary', [ClientRequestController::class, 'summary'])->name('client-requests.summary');
        Route::apiResource('client-requests', ClientRequestController::class)
            ->parameters(['client-requests' => 'clientRequest']);

        Route::post('client-requests/{clientRequest}/convert', ClientRequestConvertController::class)->name('client-requests.convert');

        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/logo', [ProjectLogoController::class, 'store']);
        Route::delete('projects/{project}/logo', [ProjectLogoController::class, 'destroy']);

        // Devis : en-tête, statut, duplication, PDF, copie signée, sections et lignes
        Route::apiResource('quotes', QuoteController::class);
        Route::post('quotes/{quote}/status', [QuoteController::class, 'status']);
        Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate']);
        Route::get('quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
        Route::post('quotes/{quote}/signed-copy', [QuoteController::class, 'signedCopy']);
        Route::get('quotes/{quote}/proposal-pdf', [QuoteController::class, 'proposalPdf'])->name('quotes.proposal-pdf');
        Route::get('quotes/{quote}/email-draft', [QuoteEmailController::class, 'draft']);
        Route::post('quotes/{quote}/send', [QuoteEmailController::class, 'send']);
        Route::post('quotes/{quote}/insert-template', [QuoteTemplateController::class, 'insert']);
        Route::post('quotes/{quote}/deposits', [QuoteMoneyController::class, 'storeDeposit']);
        Route::patch('quote-deposits/{deposit}', [QuoteMoneyController::class, 'updateDeposit']);
        Route::delete('quote-deposits/{deposit}', [QuoteMoneyController::class, 'destroyDeposit']);
        Route::post('quotes/{quote}/expenses', [QuoteMoneyController::class, 'storeExpense']);
        Route::post('quote-expenses/{expense}', [QuoteMoneyController::class, 'updateExpense']); // multipart (justificatif)
        Route::delete('quote-expenses/{expense}', [QuoteMoneyController::class, 'destroyExpense']);
        Route::post('quotes/{quote}/delivery-notes', [DeliveryNoteController::class, 'store']);
        Route::patch('delivery-notes/{note}', [DeliveryNoteController::class, 'update']);
        Route::delete('delivery-notes/{note}', [DeliveryNoteController::class, 'destroy']);
        Route::get('delivery-notes/{note}/pdf', [DeliveryNoteController::class, 'pdf'])->name('delivery-notes.pdf');
        Route::get('quote-systems', [QuoteTemplateController::class, 'systems']);
        Route::post('quote-systems', [QuoteTemplateController::class, 'storeSystem']);
        Route::patch('quote-systems/{system}', [QuoteTemplateController::class, 'updateSystem']);
        Route::delete('quote-systems/{system}', [QuoteTemplateController::class, 'destroySystem']);
        Route::get('quote-templates/{template}', [QuoteTemplateController::class, 'show']);
        Route::post('quote-templates', [QuoteTemplateController::class, 'store']);
        Route::patch('quote-templates/{template}', [QuoteTemplateController::class, 'update']);
        Route::delete('quote-templates/{template}', [QuoteTemplateController::class, 'destroy']);
        Route::post('quote-sections/{section}/save-as-template', [QuoteTemplateController::class, 'fromSection']);
        Route::post('quotes/{quote}/sections', [QuoteSectionController::class, 'store']);
        Route::post('quotes/{quote}/sections/order', [QuoteSectionController::class, 'reorder']);
        Route::patch('quote-sections/{section}', [QuoteSectionController::class, 'update']);
        Route::delete('quote-sections/{section}', [QuoteSectionController::class, 'destroy']);
        Route::post('quote-sections/{section}/lines', [QuoteLineController::class, 'store']);
        Route::post('quote-sections/{section}/lines/order', [QuoteLineController::class, 'reorder']);
        Route::patch('quote-lines/{line}', [QuoteLineController::class, 'update']);
        Route::delete('quote-lines/{line}', [QuoteLineController::class, 'destroy']);

        // Stock : marques, fournisseurs, articles, mouvements
        Route::apiResource('brands', BrandController::class);
        Route::post('brands/{brand}/logo', [StockLogoController::class, 'storeBrand']);
        Route::delete('brands/{brand}/logo', [StockLogoController::class, 'destroyBrand']);
        Route::apiResource('providers', ProviderController::class);
        Route::post('providers/{provider}/logo', [StockLogoController::class, 'storeProvider']);
        Route::delete('providers/{provider}/logo', [StockLogoController::class, 'destroyProvider']);
        Route::apiResource('storage-locations', StorageLocationController::class)->parameters(['storage-locations' => 'location']);
        Route::get('articles/summary', [ArticleController::class, 'summary'])->name('articles.summary');
        Route::apiResource('articles', ArticleController::class);
        Route::post('articles/{article}/image', [ArticleController::class, 'storeImage']);
        Route::delete('articles/{article}/image', [ArticleController::class, 'destroyImage']);
        Route::put('articles/{article}/dependencies', [ArticleController::class, 'dependencies']);
        Route::post('articles/{article}/movements', [StockMovementController::class, 'store']);
        Route::get('stock-movements', [StockMovementController::class, 'index']);
        Route::apiResource('purchases', PurchaseController::class);
        Route::post('purchases/{purchase}/lines', [PurchaseController::class, 'addLine']);
        Route::patch('purchase-lines/{line}', [PurchaseController::class, 'updateLine']);
        Route::delete('purchase-lines/{line}', [PurchaseController::class, 'removeLine']);
        Route::post('purchases/{purchase}/order', [PurchaseController::class, 'order']);
        Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel']);
        Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive']);
        Route::get('purchases/{purchase}/pdf', [PurchaseController::class, 'pdf'])->name('purchases.pdf');
        Route::post('delivery-notes/{note}/release', [DeliveryNoteController::class, 'release']);
        Route::post('delivery-notes/{note}/restock', [DeliveryNoteController::class, 'restock']);

        // Factures et paiements
        Route::get('invoices/summary', [InvoiceController::class, 'summary'])->name('invoices.summary');
        Route::apiResource('invoices', InvoiceController::class);
        Route::post('invoices/{invoice}/lines', [InvoiceController::class, 'addLine']);
        Route::patch('invoice-lines/{line}', [InvoiceController::class, 'updateLine']);
        Route::delete('invoice-lines/{line}', [InvoiceController::class, 'removeLine']);
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment']);
        Route::delete('invoice-payments/{payment}', [InvoiceController::class, 'removePayment']);
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::get('invoices/{invoice}/email-draft', [InvoiceController::class, 'emailDraft']);
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);

        // Journaux d'intervention
        Route::apiResource('journals', JournalController::class);
        Route::get('journals/{journal}/pdf', [JournalController::class, 'pdf'])->name('journals.pdf');

        // Boîte mail des demandes ; liens d'une demande vers d'autres fiches
        Route::get('inbound-emails', [InboundEmailController::class, 'index']);
        Route::post('inbound-emails/fetch', [InboundEmailController::class, 'fetch']);
        Route::get('inbound-emails/{email}', [InboundEmailController::class, 'show']);
        Route::post('inbound-emails/{email}/convert', [InboundEmailController::class, 'convert']);
        Route::post('inbound-emails/{email}/ignore', [InboundEmailController::class, 'ignore']);
        Route::get('inbound-emails/{email}/attachments/{index}', [InboundEmailController::class, 'attachment'])->whereNumber('index');
        Route::get('client-request-link-candidates', [ClientRequestLinkController::class, 'candidates']);
        Route::post('client-requests/{clientRequest}/links', [ClientRequestLinkController::class, 'store']);
        Route::delete('client-request-links/{link}', [ClientRequestLinkController::class, 'destroy']);

        // Tâches et planning
        Route::get('tasks/summary', [TaskController::class, 'summary'])->name('tasks.summary');
        Route::apiResource('tasks', TaskController::class);
        Route::post('tasks/{task}/subtasks', [TaskController::class, 'addSubtask']);
        Route::patch('subtasks/{subtask}', [TaskController::class, 'updateSubtask']);
        Route::delete('subtasks/{subtask}', [TaskController::class, 'removeSubtask']);

        // Comptabilité : dépenses, caisses, catégories, transactions, bilan
        Route::get('expenses/summary', [ExpenseController::class, 'summary'])->name('expenses.summary');
        Route::apiResource('expenses', ExpenseController::class);
        Route::post('expenses/{expense}/receipt', [ExpenseController::class, 'receipt']);
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve']);
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject']);
        Route::post('expenses/{expense}/reimburse', [ExpenseController::class, 'reimburse']);
        Route::apiResource('expense-categories', ExpenseCategoryController::class)->except('show')->parameters(['expense-categories' => 'category']);
        Route::apiResource('expense-spaces', ExpenseSpaceController::class)->parameters(['expense-spaces' => 'space']);
        Route::post('expense-spaces/{space}/fundings', [ExpenseSpaceController::class, 'fund']);
        Route::delete('expense-space-fundings/{funding}', [ExpenseSpaceController::class, 'removeFunding']);
        Route::apiResource('transactions', TransactionController::class)->except('show');
        Route::get('accounting/summary', [AccountingController::class, 'summary']);
        Route::get('accounting/ledger', [AccountingController::class, 'ledger']);

        // Notes, liens et documents de toute fiche : clients, projets (voir App\Support\Attachable)
        Route::where(['type' => implode('|', array_keys(Attachable::TYPES)), 'id' => '[0-9]+'])->group(function () {
            Route::get('{type}/{id}/notes', [NoteController::class, 'index']);
            Route::post('{type}/{id}/notes', [NoteController::class, 'store']);
            Route::get('{type}/{id}/links', [LinkController::class, 'index']);
            Route::post('{type}/{id}/links', [LinkController::class, 'store']);
            Route::get('{type}/{id}/documents', [DocumentController::class, 'index']);
            Route::post('{type}/{id}/documents', [DocumentController::class, 'store']);
        });
        Route::patch('notes/{note}', [NoteController::class, 'update']);
        Route::delete('notes/{note}', [NoteController::class, 'destroy']);
        Route::patch('links/{link}', [LinkController::class, 'update']);
        Route::delete('links/{link}', [LinkController::class, 'destroy']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        // Entreprise, banque, signature et paramètres
        Route::get('company', [CompanyController::class, 'show']);
        Route::patch('company', [CompanyController::class, 'update']);
        Route::post('company/logo', [CompanyController::class, 'storeLogo']);
        Route::delete('company/logo', [CompanyController::class, 'destroyLogo']);
        Route::get('company/{kind}', [CompanyController::class, 'showImage'])->whereIn('kind', ['signature', 'stamp']);
        Route::post('company/{kind}', [CompanyController::class, 'storeImage'])->whereIn('kind', ['signature', 'stamp']);
        Route::delete('company/{kind}', [CompanyController::class, 'destroyImage'])->whereIn('kind', ['signature', 'stamp']);
        Route::apiResource('bank-accounts', BankAccountController::class)->except('show')
            ->parameters(['bank-accounts' => 'bankAccount']);
        Route::get('settings', [SettingsController::class, 'show']);
        Route::patch('settings', [SettingsController::class, 'update']);
        Route::get('settings/numbering-preview', [SettingsController::class, 'numberingPreview']);

        Route::get('email-signatures', [EmailSignatureController::class, 'index']);
        Route::post('email-signatures', [EmailSignatureController::class, 'store']);
        Route::patch('email-signatures/{signature}', [EmailSignatureController::class, 'update']);
        Route::delete('email-signatures/{signature}', [EmailSignatureController::class, 'destroy']);
        Route::post('email-signatures/{signature}/image', [EmailSignatureController::class, 'storeImage']);
        Route::delete('email-signatures/{signature}/image', [EmailSignatureController::class, 'destroyImage']);

        Route::get('assignees', AssigneeController::class)->name('assignees');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });
});
