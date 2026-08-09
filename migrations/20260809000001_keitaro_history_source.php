<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class KeitaroHistorySource extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE stats.clicks
                ADD COLUMN source text NOT NULL DEFAULT 'slimtds',
                ADD COLUMN source_id text,
                ADD COLUMN source_data jsonb
        SQL);
        $this->execute(<<<'SQL'
            ALTER TABLE core.conversions
                ADD COLUMN source text NOT NULL DEFAULT 'slimtds',
                ADD COLUMN source_id text,
                ADD COLUMN source_data jsonb
        SQL);

        $this->execute('ALTER TABLE core.conversions DROP CONSTRAINT conversions_click_id_key');
        $this->execute("CREATE UNIQUE INDEX conversions_live_click_id_uq ON core.conversions (click_id) WHERE source = 'slimtds'");
        $this->execute('CREATE UNIQUE INDEX conversions_source_id_uq ON core.conversions (source, source_id) WHERE source_id IS NOT NULL');
        $this->execute('CREATE INDEX clicks_source_id_idx ON stats.clicks (source, source_id) WHERE source_id IS NOT NULL');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.clicks_source_id_idx');
        $this->execute('DROP INDEX IF EXISTS core.conversions_source_id_uq');
        $this->execute('DROP INDEX IF EXISTS core.conversions_live_click_id_uq');
        $this->execute('ALTER TABLE core.conversions ADD CONSTRAINT conversions_click_id_key UNIQUE (click_id)');
        $this->execute('ALTER TABLE core.conversions DROP COLUMN source, DROP COLUMN source_id, DROP COLUMN source_data');
        $this->execute('ALTER TABLE stats.clicks DROP COLUMN source, DROP COLUMN source_id, DROP COLUMN source_data');
    }
}
