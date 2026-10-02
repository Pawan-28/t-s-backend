<?php

namespace App\Console\Commands;

use App\Services\Search\ArticleSearchIndexer;
use Illuminate\Console\Command;

class ReindexArticleSearchCommand extends Command
{
    protected $signature = 'articles:reindex-search {--chunk=200 : Articles processed per batch}';

    protected $description = 'Rebuild the full-text search vector of every article (backfill after import).';

    public function handle(ArticleSearchIndexer $indexer): int
    {
        $count = $indexer->reindexAll(max(1, (int) $this->option('chunk')), fn (int $n) => $this->output->isVerbose() ? $this->line("Indexed {$n}") : null);
        $this->info("Reindexed {$count} article(s).");

        return self::SUCCESS;
    }
}
