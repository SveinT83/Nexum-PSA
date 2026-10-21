<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;

/** Shared active builder references; no message content is returned by these queries. */
final class EmailRuleFormReferences
{
    public function tags()
    {
        return Tag::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function emailCategories()
    {
        return Category::query()
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    public function ruleAccounts()
    {
        return EmailAccount::query()
            ->where('account_kind', '!=', EmailAccount::KIND_PERSONAL)
            ->where('ticket_ingress_enabled', true)
            ->orderBy('address')
            ->get(['id', 'address', 'account_kind']);
    }

    public function providerFolders()
    {
        return EmailFolder::query()
            ->whereIn('account_id', $this->ruleAccounts()->pluck('id'))
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->with('account:id,address')
            ->orderBy('account_id')
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'path', 'role']);
    }
}
