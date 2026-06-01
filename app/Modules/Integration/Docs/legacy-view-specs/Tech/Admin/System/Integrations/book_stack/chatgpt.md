BookStack Integration – Nexum / tdPSA Knowledge Provider

Date: 2026-05-11
Status: Planned
Priority: High
Difficulty: Medium–High
Estimated Time: 12–20 hours initial integration
Scope: Knowledge System / AI / Ticket Integration

Purpose

Nexum shall support integration with external knowledge systems through a provider-based architecture.

The first supported provider is:

BookStack API Documentation

The BookStack integration shall allow Nexum to use BookStack as the primary knowledge source (“Source of Truth”) while Nexum handles indexing, AI search, ticket suggestions, metadata enrichment, caching, and synchronization.

If BookStack integration is enabled and configured, it replaces the current local Knowledge module as the active article source.

The existing Knowledge module UI and ticket integrations remain, but data originates from BookStack instead of local articles.

Core Principle

BookStack owns:

Article editing
Revisions/history
Attachments
Content hierarchy
WYSIWYG/Markdown editing

Nexum owns:

Fast search
AI indexing
Embeddings
Ticket context matching
Asset correlation
Tag enrichment
AI assistant integration
Unified knowledge search

BookStack is the authoritative source.
Nexum is the intelligence/search layer.

Architecture
Knowledge Provider System

The Knowledge module must become provider-based.

Supported provider types (future-ready):

Local Knowledge
BookStack
Confluence
SharePoint
Git repositories
Markdown folders
External URLs
Vendor KB systems

BookStack is the first provider implementation.

Provider Behavior

When BookStack provider is enabled:

Local Knowledge articles become read-only or hidden.
Knowledge search uses synchronized BookStack content.
Ticket sidepanel KB suggestions use BookStack indexed articles.
AI assistant uses BookStack knowledge embeddings.
“Edit Article” actions redirect to BookStack.

Nexum shall not directly edit BookStack content in v1.

Synchronization Strategy

Nexum shall NOT perform live API searches for every request.

Instead:

Pull content from BookStack API
Store locally
Generate indexes/embeddings
Use local search for speed and AI processing

This enables:

Fast ticket suggestions
AI semantic search
Offline capability
Reduced API traffic
Better filtering and metadata handling
Database Structure
knowledge_sources

Stores external provider connections.

Fields:

id
type
name
enabled
base_url
api_token
sync_interval_minutes
last_sync_at
last_error_at
status
settings_json
knowledge_articles

Locally indexed article cache.

Fields:

id
source_id
external_id
external_type
title
slug
content
excerpt
hash
visibility
external_updated_at
last_synced_at
sync_status
knowledge_embeddings

AI semantic search storage.

Fields:

id
article_id
embedding_model
embedding_vector
chunk_index
chunk_content
knowledge_tags

Tag structure.

knowledge_article_tags

Pivot table.

knowledge_relations

Optional relation mapping.

Examples:

article ↔ asset type
article ↔ vendor
article ↔ queue
article ↔ category
Synchronization Engine
Initial Sync

Fetch:

Shelves
Books
Chapters
Pages
Attachments metadata

Store locally.

Incremental Sync

Scheduled sync:

Every 5 minutes default
Hash-based change detection
Only changed articles are re-indexed
Future

Optional:

Webhook-based sync
Real-time updates
AI Integration

Knowledge articles shall support:

Embeddings
Semantic search
AI contextual retrieval
Ticket-aware suggestions

The AI assistant may use:

Ticket content
Logs
Asset metadata
RMM alerts
Related KB articles
Historical successful matches
Ticket Integration

Ticket sidepanel shall display:

Suggested KB articles
Confidence score
Related assets/vendors
Similar historical resolutions

Search sources:

Ticket title
Description
Queue/category
Asset/vendor
Error messages
RMM alerts

This replaces the current local KB lookup system.

Editing Workflow
Version 1

Editing occurs ONLY in BookStack.

Nexum displays:

“Edit in BookStack” button
“Open Source Article”

No write-back support.

This avoids:

Sync conflicts
Revision mismatch
Permission conflicts
Lock handling
Future Optional Feature

Bidirectional synchronization.

Possible later support:

Push edits from Nexum → BookStack API
Draft sync
Revision reconciliation

Not part of v1.

Permissions

New permissions:

knowledge.provider.view
knowledge.provider.manage
knowledge.sync.manage
knowledge.sync.view
knowledge.ai.search

Only superadmin and knowledge admins may configure providers.

UI/UX Requirements

New Settings Area:

Settings → Knowledge → Providers

Views:

Provider List
Add Provider
Edit Provider
Sync Status
Sync Logs
Manual Sync Actions

Provider cards display:

Status
Last sync
Article count
Error state
Sync duration
Failure Handling

If BookStack becomes unavailable:

Nexum continues using cached articles
Sync status changes to warning/error
AI search still functions using local cache

No outage should break ticket workflows.

Search Requirements

Search must support:

Fulltext
Semantic AI search
Tags
Vendor filtering
Queue/category filtering
Asset correlation
Logging & Audit

Log:

Sync operations
API failures
Article updates
Re-index operations
Provider configuration changes

All provider actions must appear in Audit Logs.

Important Rules
BookStack is the source of truth.
Nexum must not depend on live BookStack availability.
AI search uses local indexed content.
Local cache is authoritative for runtime search performance.
v1 is read-only toward BookStack.
Integration must remain modular and provider-based.
