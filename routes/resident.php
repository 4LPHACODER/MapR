<?php

use App\Http\Controllers\Resident\DashboardController;
use App\Http\Controllers\Resident\HazardMapController;
use App\Http\Controllers\Resident\PhotoController;
use App\Http\Controllers\Resident\ProfileController;
use App\Http\Controllers\Resident\ProfilePhotoController;
use App\Http\Controllers\Resident\ReportController;
use App\Http\Controllers\Resident\WorkspaceController;
use App\Http\Controllers\Shared\ImportedDocumentController;
use App\Http\Controllers\Shared\SignatureAssetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:resident'])->prefix('resident')->group(function () {
    Route::get('api/dashboard', DashboardController::class);
    Route::get('api/map/hazards', [HazardMapController::class, 'index']);
    Route::get('api/map/critical-points', [HazardMapController::class, 'places']);
    Route::get('api/map/evacuation-sites', [HazardMapController::class, 'evacuationSites']);
    Route::post('api/map/nearest-site', [HazardMapController::class, 'nearestSite']);
    Route::post('api/map/routes', [HazardMapController::class, 'route']);
    Route::get('api/map/boundaries', [HazardMapController::class, 'boundaries']);
    Route::get('api/map/rivers', [HazardMapController::class, 'rivers']);
    Route::get('api/map/hazards/{layer}', [HazardMapController::class, 'show']);
    Route::get('api/profile', [ProfileController::class, 'show']);
    Route::patch('api/profile', [ProfileController::class, 'update']);
    Route::post('api/profile/photo', [ProfilePhotoController::class, 'store']);
    Route::delete('api/profile/photo', [ProfilePhotoController::class, 'destroy']);
    Route::get('api/profile/photo', [ProfilePhotoController::class, 'show']);
    Route::get('api/incidents', [ReportController::class, 'incidents']);
    Route::get('api/incident-updates', [ReportController::class, 'incidentUpdates']);
    Route::get('api/reports', [ReportController::class, 'index']);
    Route::post('api/reports', [ReportController::class, 'store']);
    Route::get('api/reports/{report}/document', [ReportController::class, 'document']);
    Route::get('api/reports/{report}', [ReportController::class, 'show']);
    Route::patch('api/reports/{report}', [ReportController::class, 'update']);
    Route::post('api/reports/{report}/submit', [ReportController::class, 'submit']);
    Route::delete('api/reports/{report}', [ReportController::class, 'destroy']);
    Route::post('api/reports/{report}/photos', [PhotoController::class, 'store']);
    Route::delete('api/reports/{report}/photos/{photo}', [PhotoController::class, 'destroy']);
    Route::get('api/reports/{report}/photos/{photo}', [PhotoController::class, 'show']);
    Route::get('api/reports/{report}/signature', [ReportController::class, 'signature']);
    Route::get('api/signature', [SignatureAssetController::class, 'show']);
    Route::post('api/signature', [SignatureAssetController::class, 'store']);
    Route::delete('api/signature', [SignatureAssetController::class, 'destroy']);
    Route::get('api/signature/preview', [SignatureAssetController::class, 'preview']);
    Route::get('documents/{importKey}', [ImportedDocumentController::class, 'download']);
    Route::get('/{any?}', WorkspaceController::class)->where('any', '.*')->name('resident.dashboard');
});
