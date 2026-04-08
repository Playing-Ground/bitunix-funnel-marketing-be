<?php

namespace App\Console\Commands\Pipelines;

use App\Models\BitunixInvitation;
use App\Models\CampaignVipMapping;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('martech:seed-vip-mappings
    {--source=bitunix : Default utm_source for seeded mappings}
    {--medium=affiliate : Default utm_medium for seeded mappings}
    {--force : Overwrite existing mappings instead of skipping them}')]
#[Description('Seed campaign_vip_mappings from currently-known Bitunix vip codes (one-time helper).')]
class SeedVipMappingsCommand extends Command
{
    public function handle(): int
    {
        $defaultSource = (string) $this->option('source');
        $defaultMedium = (string) $this->option('medium');
        $force = (bool) $this->option('force');

        // Pick the latest snapshot of each vip_code so we get the freshest customer_vip_code alias.
        $latestRows = BitunixInvitation::query()
            ->orderBy('vip_code')
            ->orderByDesc('date')
            ->get()
            ->unique('vip_code');

        if ($latestRows->isEmpty()) {
            $this->warn('No bitunix_invitations rows found yet. Run `php artisan martech:fetch-bitunix --pipeline=invitations` first.');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($latestRows as $row) {
            $existing = CampaignVipMapping::where('vip_code', $row->vip_code)->first();

            // utm_campaign defaults to the customer-facing alias when present,
            // otherwise to the internal vip_code itself.
            $campaign = $row->customer_vip_code ?: $row->vip_code;

            if ($existing && ! $force) {
                $skipped++;

                continue;
            }

            $payload = [
                'utm_source' => $defaultSource,
                'utm_medium' => $defaultMedium,
                'utm_campaign' => $campaign,
                'notes' => 'Auto-seeded from bitunix_invitations on '.now()->toDateString().'. Edit to match your real UTM tags.',
            ];

            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                CampaignVipMapping::create(['vip_code' => $row->vip_code] + $payload);
                $created++;
            }
        }

        $this->info("✓ Seeded {$created} new, updated {$updated}, skipped {$skipped} existing mappings.");
        $this->newLine();
        $this->line('Current mappings:');
        foreach (CampaignVipMapping::orderBy('vip_code')->get() as $m) {
            $this->line(sprintf(
                '  %-12s → %s / %s / %s',
                $m->vip_code,
                $m->utm_source ?? '(none)',
                $m->utm_medium ?? '(none)',
                $m->utm_campaign ?? '(none)',
            ));
        }
        $this->newLine();
        $this->warn('NOTE: These are placeholders. Edit `campaign_vip_mappings` to match your actual UTM tags.');

        return self::SUCCESS;
    }
}
