<?php

namespace App\Services\Articles;

use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Support\DrfQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hierarchy-aware related stories (Django ArticleViewSet.related), most specific
 * first: same subcategory > same (effective) category > same (effective) industry
 * > shared tags (count) > recency. Candidates are ALWAYS PUBLISHED and never the
 * source article, whoever is asking.
 */
class RelatedArticles
{
    public function for(Article $source, int $limit = 4): Collection
    {
        $source->loadMissing(['subcategory.category', 'legacyCategory']);
        $category = $source->effective_category;
        $categoryId = $category?->id;
        $industryId = $category?->industry_id;
        $subId = $source->subcategory_id;
        $tagIds = $source->tags()->pluck('tags.id')->all();

        // effective category of a candidate = its subcategory's category, else its legacy category.
        $ecat = 'COALESCE(sc.category_id, articles.category_id)';

        $sameSub = $subId ? ['(CASE WHEN articles.subcategory_id = ? THEN 1 ELSE 0 END)', [$subId]] : ['0', []];
        $sameCat = $categoryId ? ["(CASE WHEN $ecat = ? THEN 1 ELSE 0 END)", [$categoryId]] : ['0', []];
        $sameInd = $industryId ? ['(CASE WHEN ec.industry_id = ? THEN 1 ELSE 0 END)', [$industryId]] : ['0', []];
        if ($tagIds) {
            $in = implode(',', array_fill(0, count($tagIds), '?'));
            $shared = ["(SELECT COUNT(DISTINCT atg.tag_id) FROM article_tag atg WHERE atg.article_id = articles.id AND atg.tag_id IN ($in))", $tagIds];
        } else {
            $shared = ['0', []];
        }

        $query = ArticleQuery::published()
            ->where('articles.id', '!=', $source->id)
            ->leftJoin('subcategories as sc', 'sc.id', '=', 'articles.subcategory_id')
            ->leftJoin('categories as ec', 'ec.id', '=', DB::raw($ecat))
            ->select('articles.*')
            ->with(ArticleResource::relations());

        $where = [];
        $bindings = [];
        foreach ([$sameSub, $sameCat, $sameInd] as [$sql, $b]) {
            if ($sql !== '0') {
                $where[] = "$sql = 1";
                array_push($bindings, ...$b);
            }
        }
        if ($shared[0] !== '0') {
            $where[] = "{$shared[0]} > 0";
            array_push($bindings, ...$shared[1]);
        }
        if (! $where) {
            return new Collection;
        }
        $query->whereRaw('('.implode(' OR ', $where).')', $bindings);

        foreach ([$sameSub, $sameCat, $sameInd, $shared] as [$sql, $b]) {
            if ($sql !== '0') { // a constant tier ties every row; a bare literal would be read as a column position
                $query->orderByRaw("$sql DESC", $b);
            }
        }

        DrfQuery::orderPg($query, 'articles.published_at', 'desc');

        return $query->orderByDesc('articles.id')->limit($limit)->get();
    }
}
