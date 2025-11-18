@extends('layouts.default_tech')

@section('title', 'Documentations')

@section('pageHeader')
    <h1>Documentations</h1>
@endsection

@section('content')
# tech.documentations.index — Documentation Index View

**URL:** `tech/documentations/index`

**Access:** `documents.view.tech` (default internal scope)

**Controller / Path:** `App\Http\Controllers\Tech\Documentations\IndexController@index`

**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 4.5 hours

---

## Purpose

A central view that lists **all documentation records**, grouped by **category** and filterable by **scope** (Internal / Client / Site). Provides fast access to documents, categories, and templates for daily use by technicians and admins.

---

## Scope Handling

* **Default scope:** Internal documents.
* **Scope switcher (top-right toolbar):** Dropdown with `Internal`, `Client`, `Site`.
* Changing scope reloads the list and category tree accordingly.

---

## Layout Structure (Bootstrap)

**Top Section**

* Title: `Documentation`
* Buttons:

  * `+ New Document` → opens `tech.documentations.create`
  * `Manage Templates` → opens `tech.documentations.templates.index`
  * `Manage Categories` → opens `tech.documentations.categories.index`
* Scope switcher dropdown.

**Main Section (Split layout)**

* **Left Column (Category Menu):**

  * List of all categories, each showing name and optional badge with count.
  * Top entry: `All` (selected = global view).
  * Clicking a category filters the list to that category’s documents.

* **Right Column (Documents List):**

  * Search bar (contextual):

    * Searches within selected category.
    * If `All` selected → global search.
  * Filter bar:

    * Filter by `Created by`, `Last modified`, `Template`, `Tags`.
  * Results displayed as responsive list or cards.

    * Columns: `Title`, `Category`, `Template`, `Last updated`, `Owner`.
    * Each row clickable → opens `tech.documentations.show:{docId}`.

**Right Panel (Optional narrow column):**

* Quick info widget showing selected category details or selected document preview.
* Placeholder for future features (favorites, pinned docs, etc.).

---

## Components & Functions

* **Livewire:** `App\Livewire\Tech\Documentations\Index`

  * Handles filtering, searching, and reactive category changes.
* **Reusable components:**

  * `components.search-bar`
  * `components.category-menu`
  * `components.document-card`

---

## Smart UX Features

* Remember last selected scope and category (via session).
* Instant filtering with Livewire debounce.
* Category badges auto-update with counts.
* Keyboard shortcut: `/` focuses search.
* Empty state message: “No documents found in this category.”

---

## Notes

* Interacts with same data models as `Documentations.Create/Edit/Show`.
* Must support real-time updates (new or edited docs appear live if user has access).
* Global search results should show category icons for visual clarity.

---
@endsection

@section('sidebar')
    <h3>Left Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Right Sidebar</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection