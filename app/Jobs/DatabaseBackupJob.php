<?php

namespace App\Jobs;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DatabaseBackupJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?Team $team = null;
    public Server $server;
    public ScheduledDatabaseBackup $backup;
    public StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb $database;

    public ?string $container_name = null;
    public ?ScheduledDatabaseBackupExecution $backup_log = null;
    public string $backup_status = 'failed';
    public ?string $backup_location = null;
    public string $backup_dir;
    public string $backup_file;
    public int $size = 0;
    public ?string $backup_output = null;
    public ?S3Storage $s3 = null;

    public function __construct($backup)
    {
        $this->backup = $backup;
        $this->team = Team::find($backup->team_id);
        $this->database = data_get($this->backup, 'database');
        $this->server = $this->database->destination->server;
        $this->s3 = $this->backup->s3;
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->backup->id)];
    }

    public function uniqueId(): int
    {
        return $this->backup->id;
    }

    public function handle(): void
    {
        try {
            $status = Str::of(data_get($this->database, 'status'));
            if (!$status->startsWith('running') && $this->database->id !== 0) {
                ray('database not running');
                return;
            }
            $databaseType = $this->database->type();
            $databasesToBackup = data_get($this->backup, 'databases_to_backup');

            if (is_null($databasesToBackup)) {
                if ($databaseType === 'standalone-postgresql') {
                    $databasesToBackup = [$this->database->postgres_db];
                } else if ($databaseType === 'standalone-mongodb') {
                    $databasesToBackup = ['*'];
                } else if ($databaseType === 'standalone-mysql') {
                    $databasesToBackup = [$this->database->mysql_database];
                } else if ($databaseType === 'standalone-mariadb') {
                    $databasesToBackup = [$this->database->mariadb_database];
                } else {
                    return;
                }
            } else {
                if ($databaseType === 'standalone-postgresql') {
                    // Format: db1,db2,db3
                    $databasesToBackup = explode(',', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                } else if ($databaseType === 'standalone-mongodb') {
                    // Format: db1:collection1,collection2|db2:collection3,collection4
                    $databasesToBackup = explode('|', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                    ray($databasesToBackup);
                } else if ($databaseType === 'standalone-mysql') {
                    // Format: db1,db2,db3
                    $databasesToBackup = explode(',', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                } else if ($databaseType === 'standalone-mariadb') {
                    // Format: db1,db2,db3
                    $databasesToBackup = explode(',', $databasesToBackup);
                    $databasesToBackup = array_map('trim', $databasesToBackup);
                } else {
                    return;
                }
            }
            $this->container_name = $this->database->uuid;
            $this->backup_dir = backup_dir() . "/databases/" . Str::of($this->team->name)->slug() . '-' . $this->team->id . '/' . $this->container_name;

            if ($this->database->name === 'coolify-db') {
                $databasesToBackup = ['coolify'];
                $this->container_name = "coolify-db";
                $ip = Str::slug($this->server->ip);
                $this->backup_dir = backup_dir() . "/coolify" . "/coolify-db-$ip";
            }
            foreach ($databasesToBackup as $database) {
                $size = 0;
                ray('Backing up ' . $database);
                try {
                    if ($databaseType === 'standalone-postgresql') {
                        $this->backup_file = "/pg-dump-$database-" . Carbon::now()->timestamp . ".dmp";
                        $this->backup_location = $this->backup_dir . $this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_postgresql($database);
                    } else if ($databaseType === 'standalone-mongodb') {
                        if ($database === '*') {
                            $database = 'all';
                            $databaseName = 'all';
                        } else {
                            if (str($database)->contains(':')) {
                                $databaseName = str($database)->before(':');
                            } else {
                                $databaseName = $database;
                            }
                        }
                        $this->backup_file = "/mongo-dump-$databaseName-" . Carbon::now()->timestamp . ".tar.gz";
                        $this->backup_location = $this->backup_dir . $this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'database_name' => $databaseName,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_mongodb($database);
                    } else if ($databaseType === 'standalone-mysql') {
                        $this->backup_file = "/mysql-dump-$database-" . Carbon::now()->timestamp . ".dmp";
                        $this->backup_location = $this->backup_dir . $this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_mysql($database);
                    } else if ($databaseType === 'standalone-mariadb') {
                        $this->backup_file = "/mariadb-dump-$database-" . Carbon::now()->timestamp . ".dmp";
                        $this->backup_location = $this->backup_dir . $this->backup_file;
                        $this->backup_log = ScheduledDatabaseBackupExecution::create([
                            'database_name' => $database,
                            'filename' => $this->backup_location,
                            'scheduled_database_backup_id' => $this->backup->id,
                        ]);
                        $this->backup_standalone_mariadb($database);
                    } else {
                        throw new \Exception('Unsupported database type');
                    }
                    $size = $this->calculate_size();
                    $this->remove_old_backups();
                    if ($this->backup->save_s3) {
                        $this->upload_to_s3();
                    }
                    $this->team->notify(new BackupSuccess($this->backup, $this->database));
                    $this->backup_log->update([
                        'status' => 'success',
                        'message' => $this->backup_output,
                        'size' => $size,
                    ]);
                } catch (\Throwable $e) {
                    if ($this->backup_log) {
                        $this->backup_log->update([
                            'status' => 'failed',
                            'message' => $this->backup_output,
                            'size' => $size,
                            'filename' => null
                        ]);
                    }
                    send_internal_notification('DatabaseBackupJob failed with: ' . $e->getMessage());
                    $this->team->notify(new BackupFailed($this->backup, $this->database, $this->backup_output));
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            send_internal_notification('DatabaseBackupJob failed with: ' . $e->getMessage());
            throw $e;
        }
    }
    private function backup_standalone_mongodb(string $databaseWithCollections): void
    {
        try {
            $url = $this->database->getDbUrl(useInternal: true);
            if ($databaseWithCollections === 'all') {
                $commands[] = "mkdir -p " . $this->backup_dir;
                $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$url --gzip --archive > $this->backup_location";
            } else {
                if (str($databaseWithCollections)->contains(':')) {
                    $databaseName = str($databaseWithCollections)->before(':');
                    $collectionsToExclude = str($databaseWithCollections)->after(':')->explode(',');
                } else {
                    $databaseName = $databaseWithCollections;
                    $collectionsToExclude = collect();
                }
                $commands[] = "mkdir -p " . $this->backup_dir;
                if ($collectionsToExclude->count() === 0) {
                    $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$url --db $databaseName --gzip --archive > $this->backup_location";
                } else {
                    $commands[] = "docker exec $this->container_name mongodump --authenticationDatabase=admin --uri=$url --db $databaseName --gzip --excludeCollection " . $collectionsToExclude->implode(' --excludeCollection ') . " --archive > $this->backup_location";
                }
            }
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location . '\n\nError:' . $e->getMessage());
            throw $e;
        }
    }
    private function backup_standalone_postgresql(string $database): void
    {
        try {
            $commands[] = "mkdir -p " . $this->backup_dir;
            $commands[] = "docker exec $this->container_name pg_dump --format=custom --no-acl --no-owner --username {$this->database->postgres_user} $database > $this->backup_location";
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location . '\n\nError:' . $e->getMessage());
            throw $e;
        }
    }
    private function backup_standalone_mysql(string $database): void
    {
        try {
            $commands[] = "mkdir -p " . $this->backup_dir;
            $commands[] = "docker exec $this->container_name mysqldump -u root -p{$this->database->mysql_root_password} $database > $this->backup_location";
            ray($commands);
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location . '\n\nError:' . $e->getMessage());
            throw $e;
        }
    }
    private function backup_standalone_mariadb(string $database): void
    {
        try {
            $commands[] = "mkdir -p " . $this->backup_dir;
            $commands[] = "docker exec $this->container_name mariadb-dump -u root -p{$this->database->mariadb_root_password} $database > $this->backup_location";
            ray($commands);
            $this->backup_output = instant_remote_process($commands, $this->server);
            $this->backup_output = trim($this->backup_output);
            if ($this->backup_output === '') {
                $this->backup_output = null;
            }
            ray('Backup done for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location . '\n\nError:' . $e->getMessage());
            throw $e;
        }
    }
    private function add_to_backup_output($output): void
    {
        if ($this->backup_output) {
            $this->backup_output = $this->backup_output . "\n" . $output;
        } else {
            $this->backup_output = $output;
        }
    }

    private function calculate_size()
    {
        return instant_remote_process(["du -b $this->backup_location | cut -f1"], $this->server, false);
    }

    private function remove_old_backups(): void
    {
        if ($this->backup->number_of_backups_locally === 0) {
            $deletable = $this->backup->executions()->where('status', 'success');
        } else {
            $deletable = $this->backup->executions()->where('status', 'success')->orderByDesc('created_at')->skip($this->backup->number_of_backups_locally);
        }
        foreach ($deletable->get() as $execution) {
            delete_backup_locally($execution->filename, $this->server);
            $execution->delete();
        }
    }

    private function upload_to_s3(): void
    {
        try {
            if (is_null($this->s3)) {
                return;
            }
            $key = $this->s3->key;
            $secret = $this->s3->secret;
            // $region = $this->s3->region;
            $bucket = $this->s3->bucket;
            $endpoint = $this->s3->endpoint;
            $this->s3->testConnection();
            $commands[] = "docker run --pull=always -d --network {$this->database->destination->network} --name backup-of-{$this->backup->uuid} --rm -v $this->backup_location:$this->backup_location:ro ghcr.io/coollabsio/coolify-helper >/dev/null 2>&1";

            $commands[] = "docker exec backup-of-{$this->backup->uuid} mc config host add temporary {$endpoint} $key $secret";
            $commands[] = "docker exec backup-of-{$this->backup->uuid} mc cp $this->backup_location temporary/$bucket{$this->backup_dir}/";
            instant_remote_process($commands, $this->server);
            $this->add_to_backup_output('Uploaded to S3.');
            ray('Uploaded to S3. ' . $this->backup_location . ' to s3://' . $bucket . $this->backup_dir);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            throw $e;
        } finally {
            $command = "docker rm -f backup-of-{$this->backup->uuid}";
            instant_remote_process([$command], $this->server);
        }
    }
}
