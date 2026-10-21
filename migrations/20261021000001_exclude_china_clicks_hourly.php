<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExcludeChinaClicksHourly extends AbstractMigration
{
    public function up(): void
    {
        $this->rebuild("AND LOWER(country) IS DISTINCT FROM 'cn'");
    }

    public function down(): void
    {
        $this->rebuild('');
    }

    private function rebuild(string $countryFilter): void
    {
        $owner = $this->fetchRow("SELECT pg_get_userbyid(relowner) AS name, current_user AS runner FROM pg_class WHERE oid = 'stats.clicks_hourly'::regclass");
        if ($owner['name'] !== $owner['runner']) {
            throw new \RuntimeException('Run the clicks aggregate migration as its existing owner');
        }
        $grants = $this->fetchAll(<<<'SQL'
            SELECT acl.grantee, roles.rolname, acl.privilege_type, acl.is_grantable
            FROM pg_class c
            CROSS JOIN LATERAL aclexplode(c.relacl) acl
            LEFT JOIN pg_roles roles ON roles.oid = acl.grantee
            WHERE c.oid = 'stats.clicks_hourly'::regclass AND acl.grantee <> c.relowner
        SQL);
        $this->execute('DROP MATERIALIZED VIEW stats.clicks_hourly');
        $this->execute(<<<SQL
            CREATE MATERIALIZED VIEW stats.clicks_hourly AS
            SELECT campaign_id, date_trunc('hour', created_at) AS hour,
                   count(*) AS clicks,
                   count(*) FILTER (WHERE NOT is_bot) AS clicks_human,
                   count(*) FILTER (WHERE is_uniq AND NOT is_bot) AS clicks_uniq,
                   count(*) FILTER (WHERE is_bot) AS clicks_bot
            FROM stats.clicks
            WHERE created_at >= now() - interval '90 days' {$countryFilter}
            GROUP BY 1, 2
        SQL);
        $this->execute('CREATE UNIQUE INDEX clicks_hourly_pk ON stats.clicks_hourly (campaign_id, hour)');
        $this->execute('CREATE INDEX clicks_hourly_hour_idx ON stats.clicks_hourly (hour DESC)');
        foreach ($grants as $grant) {
            $role = (int)$grant['grantee'] === 0 ? 'PUBLIC'
                : '"' . str_replace('"', '""', (string)$grant['rolname']) . '"';
            $option = in_array($grant['is_grantable'], [true, 't', '1', 1], true) ? ' WITH GRANT OPTION' : '';
            $this->execute('GRANT ' . $grant['privilege_type'] . ' ON stats.clicks_hourly TO ' . $role . $option);
        }
    }
}
