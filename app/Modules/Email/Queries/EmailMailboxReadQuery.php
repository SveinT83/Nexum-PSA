<?php

namespace App\Modules\Email\Queries;

use App\Models\Core\User;
use App\Modules\Email\Concerns\QueriesEmailMailboxPlacements;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Pagination\LengthAwarePaginator;

/** Request-local state around the exact browser query; never instantiate a Livewire component. */
final class EmailMailboxReadQuery
{
    use QueriesEmailMailboxPlacements;

    private const LEGACY_CONVERSATION_LIST_LIMIT = 100;

    private const LEGACY_CONVERSATION_INITIAL_THREAD_LIMIT = 50;

    private const LEGACY_CONVERSATION_THREAD_LIMIT = 200;

    public bool $legacyConversationListTruncated = false;

    private mixed $accountId;

    private mixed $folderId;

    private string $viewMode;

    private string $search;

    private string $listFilter;

    private int $perPage;

    private int $pageNumber;

    private int $legacyConversationThreadLimit;

    private ?array $ordinaryAccountIdsCache = null;

    private array $ordinaryViewAccessByAccount = [];

    private array $unreadForMeByMessage = [];

    public function __construct(private readonly User $actor, array $filters = [])
    {
        $this->accountId = $filters['account_id'] ?? '';
        $this->folderId = $filters['folder_id'] ?? '';
        $this->viewMode = $filters['view'] ?? 'unread';
        $this->search = $filters['q'] ?? '';
        $this->listFilter = $filters['filter'] ?? 'all';
        $this->perPage = (int) ($filters['per_page'] ?? 25);
        $this->pageNumber = (int) ($filters['page'] ?? 1);
        $this->legacyConversationThreadLimit = (int) ($filters['legacy_limit'] ?? 50);
    }

    public function listing(array $accountIds): LengthAwarePaginator
    {
        return $this->conversationPlacementPaginator($accountIds);
    }

    public function conversation(EmailMailboxPlacement $placement, array $accountIds): array
    {
        if ($placement->email_conversation_id) {
            $page = $this->orderPlacements($this->basePlacementQuery($accountIds)
                ->where('account_id', $placement->account_id)
                ->where('email_conversation_id', $placement->email_conversation_id))
                ->paginate($this->perPage, ['*'], 'page', $this->pageNumber);

            return ['placements' => $page->getCollection(), 'total' => $page->total(),
                'truncated' => $page->hasMorePages(), 'can_load_more' => $page->hasMorePages(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()];
        }

        return $this->conversationThread($placement, $accountIds);
    }

    private function user(): ?User
    {
        return $this->actor;
    }

    private function getPage(): int
    {
        return $this->pageNumber;
    }
}
