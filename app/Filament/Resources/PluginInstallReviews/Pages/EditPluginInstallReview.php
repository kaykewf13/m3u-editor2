<?php

namespace App\Filament\Resources\PluginInstallReviews\Pages;

use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Models\PluginInstallReview;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class EditPluginInstallReview extends EditRecord
{
    protected static string $resource = PluginInstallReviewResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record;
    }

    protected function getHeaderActions(): array
    {
        /** @var PluginInstallReview $record */
        $record = $this->record;

        return [
            ActionGroup::make([
                Action::make('scan')
                    ->label('Run ClamAV Scan')
                    ->icon('heroicon-o-shield-check')
                    ->hidden(fn () => PluginInstallReviewResource::useFakeScanner())
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->scanInstallReview($record);

                            Notification::make()
                                ->title('Scan completed')
                                ->body($review->scan_summary ?: "Scan status: {$review->scan_status}")
                                ->color($review->scan_status === 'clean' ? 'success' : 'warning')
                                ->send();

                            $this->refreshFormData([
                                'status',
                                'scan_status',
                                'scan_summary',
                                'scan_details_json',
                            ]);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Scan failed')
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('approve')
                    ->label('Install')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->approveInstallReview($record, false, auth()->id());

                            Notification::make()
                                ->success()
                                ->title('Plugin installed')
                                ->body("Plugin install #{$review->id} installed [{$review->plugin_id}].")
                                ->send();

                            $this->refreshFormData(['status', 'installed_path', 'installed_at']);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Plugin install failed')
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('install_and_trust')
                    ->label('Install And Trust')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->approveInstallReview($record, true, auth()->id());

                            Notification::make()
                                ->success()
                                ->title('Plugin installed and trusted')
                                ->body("Plugin install #{$review->id} installed and trusted [{$review->plugin_id}].")
                                ->send();

                            $this->refreshFormData(['status', 'installed_path', 'installed_at']);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Plugin install failed')
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject Install')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->rejectInstallReview($record, auth()->id());

                            Notification::make()
                                ->success()
                                ->title('Plugin install rejected')
                                ->body("Plugin install #{$review->id} was rejected.")
                                ->send();

                            $this->refreshFormData(['status', 'review_notes']);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Reject install failed')
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('discard')
                    ->label('Discard Review')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->hidden(fn () => $record->status === 'installed')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            app(PluginManager::class)->discardInstallReview($record);

                            Notification::make()
                                ->success()
                                ->title('Plugin install discarded')
                                ->send();

                            $this->redirect(PluginInstallReviewResource::getUrl());
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Discard review failed')
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
            ])->label('Actions')->button(),
            DeleteAction::make()
                ->label('Delete Record')
                ->modalDescription('Permanently removes this install log entry. The plugin itself (if installed) is not affected.')
                ->successRedirectUrl(PluginInstallReviewResource::getUrl()),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
