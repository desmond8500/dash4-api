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
use App\Http\Controllers\Api\V1\BadgeController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DeliveryNoteController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EmailSignatureController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseSpaceController;
use App\Http\Controllers\Api\V1\InboundEmailController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DoeController;
use App\Http\Controllers\Api\V1\InstalledSystemController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PlanIconController;
use App\Http\Controllers\Api\V1\QuantityController;
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
use App\Http\Controllers\Api\V1\QuoteSiteController;
use App\Http\Controllers\Api\V1\QuoteEmailController;
use App\Http\Controllers\Api\V1\QuoteLineController;
use App\Http\Controllers\Api\V1\QuoteMoneyController;
use App\Http\Controllers\Api\V1\QuoteSectionController;
use App\Http\Controllers\Api\V1\QuoteTemplateController;
use App\Http\Controllers\Api\V1\ReceptionReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StockLogoController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StorageLocationController;
use App\Http\Controllers\Api\V1\SheetController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TechAccessRequestController;
use App\Http\Controllers\Api\V1\TechShareController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CvController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
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
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // Espace personnel : profil et CV
        Route::patch('me', [ProfileController::class, 'update']);
        Route::post('me/avatar', [ProfileController::class, 'avatar']);
        Route::put('me/password', [ProfileController::class, 'password'])->middleware('throttle:6,1');
        Route::get('me/cvs', [CvController::class, 'index']);
        Route::post('me/cvs', [CvController::class, 'store']);
        Route::get('cvs/{cv}', [CvController::class, 'show']);
        Route::patch('cvs/{cv}', [CvController::class, 'update']);
        Route::delete('cvs/{cv}', [CvController::class, 'destroy']);
        Route::post('cvs/{cv}/duplicate', [CvController::class, 'duplicate']);
        Route::post('cvs/{cv}/photo', [CvController::class, 'photo']);
        Route::post('cvs/{cv}/projects', [CvController::class, 'importProjects']);
        Route::get('cvs/{cv}/pdf', [CvController::class, 'pdf']);
        Route::get('users/{user}/cvs', [CvController::class, 'shared']);

        // Exports comptables (CSV, FEC)
        Route::get('exports', [\App\Http\Controllers\Api\V1\ExportController::class, 'index']);
        Route::get('exports/{type}', [\App\Http\Controllers\Api\V1\ExportController::class, 'download'])->where('type', '[a-z_]+');

        // Sauvegardes (administrateurs)
        Route::get('backups', [BackupController::class, 'index']);
        Route::post('backups', [BackupController::class, 'store']);
        Route::get('backups/{name}', [BackupController::class, 'download'])->where('name', '[A-Za-z0-9.\-]+');
        Route::delete('backups/{name}', [BackupController::class, 'destroy'])->where('name', '[A-Za-z0-9.\-]+');

        // Messagerie interne (tous profils ; chacun ne voit que ses conversations)
        Route::get('messaging/users', [ConversationController::class, 'users']);
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('conversations/unread', [ConversationController::class, 'unread']);
        Route::post('conversations', [ConversationController::class, 'store']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
        Route::post('conversations/{conversation}/members', [ConversationController::class, 'addMembers']);
        Route::delete('conversations/{conversation}/members/{user}', [ConversationController::class, 'removeMember']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send']);
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read']);
        Route::patch('messages/{message}', [ConversationController::class, 'updateMessage']);
        Route::delete('messages/{message}', [ConversationController::class, 'destroyMessage']);

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
        // Suivi de chantier : bâtiments, systèmes installés, équipements, avancement, PV de réception
        Route::get('projects/{project}/summary-pdf', \App\Http\Controllers\Api\V1\ProjectSummaryController::class);
        Route::get('projects/{project}/site', [SiteController::class, 'show']);
        Route::post('projects/{project}/buildings', [SiteController::class, 'storeBuilding']);
        Route::patch('buildings/{building}', [SiteController::class, 'updateBuilding']);
        Route::delete('buildings/{building}', [SiteController::class, 'destroyBuilding']);
        Route::post('buildings/{building}/levels', [SiteController::class, 'storeLevel']);
        Route::patch('levels/{level}', [SiteController::class, 'updateLevel']);
        Route::delete('levels/{level}', [SiteController::class, 'destroyLevel']);
        Route::post('levels/{level}/rooms', [SiteController::class, 'storeRoom']);
        Route::patch('rooms/{room}', [SiteController::class, 'updateRoom']);
        Route::delete('rooms/{room}', [SiteController::class, 'destroyRoom']);
        Route::get('systems', [SiteController::class, 'systems']);
        Route::post('systems', [SiteController::class, 'storeSystem']);
        Route::patch('systems/{system}', [SiteController::class, 'updateSystem']);
        Route::delete('systems/{system}', [SiteController::class, 'destroySystem']);
        Route::post('projects/{project}/installed-systems', [InstalledSystemController::class, 'store']);
        Route::patch('installed-systems/{installedSystem}', [InstalledSystemController::class, 'update']);
        Route::delete('installed-systems/{installedSystem}', [InstalledSystemController::class, 'destroy']);
        Route::post('installed-systems/{installedSystem}/progress', [InstalledSystemController::class, 'storeProgress']);
        Route::delete('system-progress/{progress}', [InstalledSystemController::class, 'destroyProgress']);
        Route::post('installed-systems/{installedSystem}/equipment', [InstalledSystemController::class, 'storeEquipment']);
        Route::patch('equipment/{equipment}', [InstalledSystemController::class, 'updateEquipment']);
        Route::post('equipment/{equipment}/split', [InstalledSystemController::class, 'splitEquipment']);
        Route::delete('equipment/{equipment}', [InstalledSystemController::class, 'destroyEquipment']);
        Route::get('projects/{project}/reception-reports', [ReceptionReportController::class, 'index']);
        Route::post('projects/{project}/reception-reports', [ReceptionReportController::class, 'store']);
        Route::get('reception-reports/{receptionReport}', [ReceptionReportController::class, 'show']);
        Route::patch('reception-reports/{receptionReport}', [ReceptionReportController::class, 'update']);
        Route::delete('reception-reports/{receptionReport}', [ReceptionReportController::class, 'destroy']);
        Route::post('reception-reports/{receptionReport}/logos/{party}', [ReceptionReportController::class, 'storeLogo']);
        Route::delete('reception-reports/{receptionReport}/logos/{party}', [ReceptionReportController::class, 'destroyLogo']);
        Route::get('reception-reports/{receptionReport}/pdf', [ReceptionReportController::class, 'pdf']);
        Route::get('projects/{project}/plans', [PlanController::class, 'index']);
        Route::post('projects/{project}/plans', [PlanController::class, 'store']);
        Route::patch('plans/{plan}', [PlanController::class, 'update']);
        Route::delete('plans/{plan}', [PlanController::class, 'destroy']);
        Route::get('plans/{plan}/file', [PlanController::class, 'file']);
        Route::post('plans/{plan}/markers', [PlanController::class, 'storeMarker']);
        Route::patch('plan-markers/{marker}', [PlanController::class, 'updateMarker']);
        Route::post('plans/{plan}/render', [PlanController::class, 'storeRender']);
        Route::get('plans/{plan}/render/{page}', [PlanController::class, 'render'])->whereNumber('page');
        Route::get('plans/{plan}/pdf', [PlanController::class, 'pdf']);
        // Bibliothèque d'icônes des plans
        Route::get('plan-icons', [PlanIconController::class, 'index']);
        Route::post('plan-icons', [PlanIconController::class, 'store']);
        Route::post('plan-icons/install', [PlanIconController::class, 'install']);
        Route::match(['post', 'patch'], 'plan-icons/{planIcon}', [PlanIconController::class, 'update']);
        Route::delete('plan-icons/{planIcon}', [PlanIconController::class, 'destroy']);
        Route::get('plan-icons/{planIcon}/file', [PlanIconController::class, 'file']);
        Route::delete('plan-markers/{marker}', [PlanController::class, 'destroyMarker']);
        Route::get('projects/{project}/quantities', [QuantityController::class, 'index']);
        Route::post('projects/{project}/quantities', [QuantityController::class, 'store']);
        Route::get('quantities/{quantity}', [QuantityController::class, 'show']);
        Route::patch('quantities/{quantity}', [QuantityController::class, 'update']);
        Route::delete('quantities/{quantity}', [QuantityController::class, 'destroy']);
        Route::post('quantities/{quantity}/quote', [QuantityController::class, 'toQuote']);
        Route::get('projects/{project}/sheets', [SheetController::class, 'index']);
        Route::post('projects/{project}/sheets', [SheetController::class, 'store']);
        Route::get('sheets/{sheet}', [SheetController::class, 'show']);
        Route::patch('sheets/{sheet}', [SheetController::class, 'update']);
        Route::delete('sheets/{sheet}', [SheetController::class, 'destroy']);
        Route::get('sheets/{sheet}/pdf', [SheetController::class, 'pdf']);
        Route::get('projects/{project}/doe', DoeController::class);

        Route::get('dashboard', [DashboardController::class, 'show']);
        Route::put('dashboard/layout', [DashboardController::class, 'layout']);

        // Badges
        Route::get('badge-templates', [BadgeController::class, 'templates']);
        Route::post('badge-templates', [BadgeController::class, 'storeTemplate']);
        Route::patch('badge-templates/{template}', [BadgeController::class, 'updateTemplate']);
        Route::delete('badge-templates/{template}', [BadgeController::class, 'destroyTemplate']);
        Route::post('badge-templates/{template}/images/{kind}', [BadgeController::class, 'storeTemplateImage']);
        Route::delete('badge-templates/{template}/images/{kind}', [BadgeController::class, 'destroyTemplateImage']);
        Route::get('projects/{project}/badges', [BadgeController::class, 'index']);
        Route::post('projects/{project}/badges', [BadgeController::class, 'store']);
        Route::get('projects/{project}/badges/pdf', [BadgeController::class, 'pdf']);
        Route::patch('badges/{badge}', [BadgeController::class, 'update']);
        Route::delete('badges/{badge}', [BadgeController::class, 'destroy']);
        Route::post('badges/{badge}/photo', [BadgeController::class, 'storePhoto']);

        // Contrats et forfaits
        Route::get('flat-rates', [ContractController::class, 'flatRates']);
        Route::post('flat-rates', [ContractController::class, 'storeFlatRate']);
        Route::patch('flat-rates/{flatRate}', [ContractController::class, 'updateFlatRate']);
        Route::delete('flat-rates/{flatRate}', [ContractController::class, 'destroyFlatRate']);
        // Parc automobile
        Route::get('vehicles/options', [VehicleController::class, 'options']);
        Route::apiResource('vehicles', VehicleController::class);
        Route::post('vehicles/{vehicle}/papers', [VehicleController::class, 'storePaper']);
        Route::patch('vehicles/{vehicle}/papers/{paper}', [VehicleController::class, 'updatePaper']);
        Route::delete('vehicles/{vehicle}/papers/{paper}', [VehicleController::class, 'destroyPaper']);
        Route::post('vehicles/{vehicle}/mileages', [VehicleController::class, 'storeMileage']);
        Route::delete('vehicles/{vehicle}/mileages/{mileage}', [VehicleController::class, 'destroyMileage']);
        Route::post('vehicles/{vehicle}/maintenances', [VehicleController::class, 'storeMaintenance']);
        Route::patch('vehicles/{vehicle}/maintenances/{maintenance}', [VehicleController::class, 'updateMaintenance']);
        Route::delete('vehicles/{vehicle}/maintenances/{maintenance}', [VehicleController::class, 'destroyMaintenance']);

        Route::apiResource('contracts', ContractController::class);
        Route::post('contracts/{contract}/invoice', [ContractController::class, 'invoice']);
        Route::get('contracts/{contract}/pdf', [ContractController::class, 'pdf']);

        Route::post('projects/{project}/logo', [ProjectLogoController::class, 'store']);
        Route::delete('projects/{project}/logo', [ProjectLogoController::class, 'destroy']);

        // Devis : en-tête, statut, duplication, PDF, copie signée, sections et lignes
        Route::apiResource('quotes', QuoteController::class);
        Route::post('quotes/{quote}/status', [QuoteController::class, 'status']);
        Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate']);
        Route::post('quotes/{quote}/site-equipment', [QuoteSiteController::class, 'store']);
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
        Route::patch('quote-lines/{line}/article', [QuoteLineController::class, 'link']);
        Route::post('quotes/{quote}/link-articles', [QuoteLineController::class, 'linkAll']);
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
        Route::get('invoices/schedule', [InvoiceController::class, 'schedule']);
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
        Route::get('invoices/{invoice}/reminder-draft', [InvoiceController::class, 'reminderDraft']);
        Route::post('invoices/{invoice}/remind', [InvoiceController::class, 'remind']);
        Route::post('invoices/{invoice}/credit-notes', [\App\Http\Controllers\Api\V1\CreditNoteController::class, 'store']);
        Route::get('credit-notes/{creditNote}/pdf', [\App\Http\Controllers\Api\V1\CreditNoteController::class, 'pdf']);

        // Journaux d'intervention
        Route::apiResource('journals', JournalController::class);
        Route::get('journals/{journal}/pdf', [JournalController::class, 'pdf'])->name('journals.pdf');
        Route::apiResource('tech-shares', TechShareController::class);
        Route::post('tech-shares/{tech_share}/read', [TechShareController::class, 'read']);
        Route::post('tech-shares/{tech_share}/expire', [TechShareController::class, 'expire']);
        Route::apiResource('tech-access-requests', TechAccessRequestController::class)->except('update');
        Route::post('tech-access-requests/{tech_access_request}/decide', [TechAccessRequestController::class, 'decide']);
        Route::get('journals/{journal}/email-draft', [JournalController::class, 'emailDraft']);
        Route::post('journals/{journal}/send', [JournalController::class, 'send'])->middleware('throttle:20,1');

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
        Route::where(['type' => implode('|', Attachable::COMMENTABLE), 'id' => '[0-9]+'])->group(function () {
            Route::get('{type}/{id}/comments', [CommentController::class, 'index']);
            Route::post('{type}/{id}/comments', [CommentController::class, 'store'])->middleware('throttle:60,1');
            Route::get('{type}/{id}/mentionable', [CommentController::class, 'mentionable']);
        });
        Route::patch('comments/{comment}', [CommentController::class, 'update']);
        Route::delete('comments/{comment}', [CommentController::class, 'destroy']);
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
