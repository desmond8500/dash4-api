<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\Brand;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalSection;
use App\Models\TechShare;
use App\Models\Link;
use App\Models\Note;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Quote;
use App\Models\StockMovement;
use App\Models\Task;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Noms courts stockés dans les relations polymorphes (téléphones, e-mails, notes…)
        Relation::morphMap([
            'client' => Client::class,
            'contact' => Contact::class,
            'client_request' => ClientRequest::class,
            'project' => Project::class,
            'quote' => Quote::class,
            'invoice' => Invoice::class,
            'article' => Article::class,
            'provider' => Provider::class,
            'purchase' => Purchase::class,
            'expense' => Expense::class,
            'task' => Task::class,
            'journal' => Journal::class,
            'journal_section' => JournalSection::class,
            'tech_share' => TechShare::class,
            'brand' => Brand::class,
            'delivery_note' => DeliveryNote::class,
            'stock_movement' => StockMovement::class,
            'note' => Note::class,
            'link' => Link::class,
            'document' => Document::class,
        ]);

        // En développement : erreur si on essaie d'enregistrer un attribut non autorisé
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // 5 tentatives de connexion par minute pour un même e-mail et une même adresse IP
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // API : 120 requêtes par minute par utilisateur (ou par IP sans connexion)
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
