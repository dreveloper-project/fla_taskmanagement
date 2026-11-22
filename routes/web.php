<?php

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BunkerDocumentDownloadController;
use App\Http\Controllers\CompletedServiceArchiveDownloadController;
use App\Http\Controllers\CompletedSpbArchiveDownloadController;
use App\Http\Controllers\EntityProfilePhotoController;
use App\Http\Controllers\ProfilePictureController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\SafetyCertificateDocumentDownloadController;
use App\Http\Controllers\ServiceArchiveDownloadController;
use App\Http\Controllers\ShipNationalityDocumentDownloadController;
use App\Http\Controllers\SijilDocumentDownloadController;
use App\Http\Controllers\SpbDocumentDownloadController;
use App\Http\Controllers\VesselDocumentDownloadController;
use App\Http\Controllers\VesselDocumentVersionDownloadController;
use App\Models\SpbService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:registration')->name('register.store');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'can:access-system'])->group(function (): void {
    Route::livewire('/', 'pages::dashboard.overview')->name('home');
    Route::livewire('/dashboard/operational-calendar', 'pages::dashboard.operational-calendar')->name('dashboard.operational-calendar');
    Route::livewire('/dashboard/staff-management', 'pages::dashboard.staff-management')->middleware('can:access-admin')->name('staff.index');
    Route::livewire('/dashboard/vessel', 'pages::dashboard.vessel')->name('dashboard.vessel');
    Route::livewire('/dashboard/vessel/create', 'pages::dashboard.vessel')->middleware('can:manage-vessels')->name('vessels.create');
    Route::livewire('/dashboard/vessel/{vessel}/edit', 'pages::dashboard.vessel')->whereNumber('vessel')->middleware('can:manage-vessels')->name('vessels.edit');
    Route::get('/vessels/{vessel}/documents/{document}/download', VesselDocumentDownloadController::class)
        ->scopeBindings()->name('vessel-documents.download');
    Route::get('/vessels/{vessel}/picture', [EntityProfilePhotoController::class, 'vessel'])->name('vessels.picture');
    Route::livewire('/dashboard/finance', 'pages::dashboard.finance')->middleware('can:access-finance')->name('finance.index');
    Route::livewire('/dashboard/reports', 'pages::dashboard.reports')->middleware('can:access-reports')->name('reports.index');
    Route::get('/dashboard/reports/export/{kind}', ReportExportController::class)->middleware('can:access-reports')->name('reports.export');
    Route::match(['get', 'post'], '/services/{type}/{service}/archive/download', ServiceArchiveDownloadController::class)
        ->whereNumber('service')->name('service-archives.download');
    Route::match(['get', 'post'], '/services/{type}/archives/download', CompletedServiceArchiveDownloadController::class)
        ->name('service-archives.completed');
    Route::livewire('/dashboard/services/spb', 'pages::services.spb')->name('spb.index');
    Route::match(['get', 'post'], '/dashboard/services/spb/archives/download', CompletedSpbArchiveDownloadController::class)->name('spb.archives.download');
    Route::livewire('/dashboard/services/bunker', 'pages::services.bunker')->name('bunker.index');
    Route::livewire('/dashboard/services/ship-nationality', 'pages::services.ship-nationality')->name('ship-nationality.index');
    Route::livewire('/dashboard/services/ship-nationality/create', 'pages::services.ship-nationality-form')->middleware('can:manage-services')->name('ship-nationality.create');
    Route::livewire('/dashboard/services/ship-nationality/{service}/edit', 'pages::services.ship-nationality-form')->whereNumber('service')->middleware('can:manage-services')->name('ship-nationality.edit');
    Route::livewire('/dashboard/services/ship-nationality/{service}', 'pages::services.ship-nationality-detail')->whereNumber('service')->name('ship-nationality.show');
    Route::get('/ship-nationality/{service}/documents/{document}/download', ShipNationalityDocumentDownloadController::class)->scopeBindings()->name('ship-nationality.documents.download');
    Route::livewire('/dashboard/services/sijil', 'pages::services.sijil')->name('sijil.index');
    Route::livewire('/dashboard/services/sijil/create', 'pages::services.sijil-form')->middleware('can:manage-services')->name('sijil.create');
    Route::livewire('/dashboard/services/sijil/{service}/edit', 'pages::services.sijil-form')->whereNumber('service')->middleware('can:manage-services')->name('sijil.edit');
    Route::livewire('/dashboard/services/sijil/{service}', 'pages::services.sijil-detail')->whereNumber('service')->name('sijil.show');
    Route::get('/sijil/{service}/documents/{document}/download', SijilDocumentDownloadController::class)->scopeBindings()->name('sijil.documents.download');
    Route::livewire('/dashboard/services/bunker/create', 'pages::services.bunker-form')->middleware('can:manage-services')->name('bunker.create');
    Route::livewire('/dashboard/services/bunker/{service}/edit', 'pages::services.bunker-form')->whereNumber('service')->middleware('can:manage-services')->name('bunker.edit');
    Route::livewire('/dashboard/services/bunker/{service}', 'pages::services.bunker-detail')->whereNumber('service')->name('bunker.show');
    Route::get('/bunker/{service}/documents/{document}/download', BunkerDocumentDownloadController::class)->scopeBindings()->name('bunker.documents.download');
    Route::livewire('/dashboard/services/safety-certificates', 'pages::services.safety-certificates')->name('safety-certificates.index');
    Route::livewire('/dashboard/services/safety-certificates/create', 'pages::services.safety-certificate-form')->middleware('can:manage-services')->name('safety-certificates.create');
    Route::livewire('/dashboard/services/safety-certificates/{service}/edit', 'pages::services.safety-certificate-form')->whereNumber('service')->middleware('can:manage-services')->name('safety-certificates.edit');
    Route::livewire('/dashboard/services/safety-certificates/{service}', 'pages::services.safety-certificate-detail')->whereNumber('service')->name('safety-certificates.show');
    Route::get('/safety-certificates/{service}/documents/{document}/download', SafetyCertificateDocumentDownloadController::class)
        ->scopeBindings()->name('safety-certificates.documents.download');
    Route::get('/vessels/{vessel}/document-versions/{version}/download', VesselDocumentVersionDownloadController::class)
        ->name('vessel-document-versions.download');
    Route::get('/dashboard/services/spb/passenger', fn (): RedirectResponse => redirect()->route('spb.index', request()->query()));
    Route::redirect('/dashboard/services/spb/passenger/create', '/dashboard/services/spb/create');
    Route::get('/dashboard/services/spb/passenger/{spb}/edit', fn (SpbService $spb): RedirectResponse => redirect()->route('spb.edit', $spb));
    Route::get('/dashboard/services/spb/passenger/{spb}', fn (SpbService $spb): RedirectResponse => redirect()->route('spb.show', $spb));
    Route::livewire('/dashboard/services/spb/create', 'pages::services.spb-form')->middleware('can:manage-services')->name('spb.create');
    Route::livewire('/dashboard/services/spb/{spb}/edit', 'pages::services.spb-form')->whereNumber('spb')->middleware('can:manage-services')->name('spb.edit');
    Route::livewire('/dashboard/services/spb/{spb}', 'pages::services.spb-detail')->whereNumber('spb')->name('spb.show');
    Route::get('/spb/{spb}/documents/{document}/download', SpbDocumentDownloadController::class)
        ->scopeBindings()->name('spb.documents.download');
    Route::get('/profile/picture', ProfilePictureController::class)->name('profile.picture');
    Route::get('/settings/account', [AccountSettingsController::class, 'edit'])->name('account.settings');
    Route::patch('/settings/account', [AccountSettingsController::class, 'update'])->name('account.update');
    Route::put('/settings/password', [AccountPasswordController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');
});
