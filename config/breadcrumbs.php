<?php

/**
 * Breadcrumbs Configuration
 *
 * This file defines the breadcrumb navigation structure for the application.
 * Each key corresponds to a route name, and the value is an array of steps.
 * Each step should have a 'label' and an optional 'route'.
 *
 * IMPORTANT: Because many route names contain dots, the breadcrumb helper
 * accesses this array directly to avoid Laravel's dot-notation configuration
 * nesting behavior.
 */

return [

    // Admin Dashboard

    'tech.admin.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
    ],

    // Templates Management - Main Hub
    'admin.system.templatesManagement.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
    ],

    // Documentation Templates - List
    'admin.system.templatesManagement.doc.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
        ['label' => 'Documentation'],
    ],

    // Documentation Templates - Create/Edit Form
    'admin.system.templatesManagement.doc.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
        ['label' => 'Documentation', 'route' => 'tech.admin.system.templatesManagement.doc.index'],
        ['label' => 'Form'],
    ],

    'admin.system.templatesManagement.doc.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
        ['label' => 'Documentation', 'route' => 'tech.admin.system.templatesManagement.doc.index'],
        ['label' => 'Form'],
    ],

    //Admin User Management
    'admin.user_management.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'User Management'],
    ],

    //Admin Roles Management
    'admin.user_management.roles.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'User Management', 'route' => 'tech.admin.user_management.index'],
        ['label' => 'Roles'],
    ],

    //Admin Roles Form
    'admin.user_management.roles.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'User Management', 'route' => 'tech.admin.user_management.index'],
        ['label' => 'Roles', 'route' => 'tech.admin.user_management.roles.index'],
        ['label' => 'Edit form'],
    ],

    'admin.user_management.roles.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'User Management', 'route' => 'tech.admin.user_management.index'],
        ['label' => 'Roles', 'route' => 'tech.admin.user_management.roles.index'],
        ['label' => 'Edit form'],
    ],


    //Admin Permissions Management
    'admin.user_management.permissions.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'User Management', 'route' => 'tech.admin.user_management.index'],
        ['label' => 'Permissions'],
    ],

    //Admin Permissions Form
    'admin.user_management.permissions.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'User Management', 'route' => 'tech.admin.user_management.index'],
        ['label' => 'Permissions', 'route' => 'tech.admin.user_management.permissions.index'],
        ['label' => 'Edit form'],
    ],

    'admin.user_management.permissions.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'User Management', 'route' => 'tech.admin.user_management.index'],
        ['label' => 'Permissions', 'route' => 'tech.admin.user_management.permissions.index'],
        ['label' => 'Edit form'],
    ],


    //Tech Documentations INDEX
    'documentations.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Documentation'],
    ],

    //Tech Documentations CREATE
    'documentations.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Documentation', 'route' => 'tech.documentations.index'],
        ['label' => 'Form'],
    ],

    // Assets
    'tech.assets.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Assets', 'route' => 'tech.assets.index'],
    ],

    'tech.assets.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Assets', 'route' => 'tech.assets.index'],
        ['label' => 'New Asset'],
    ],

    'tech.assets.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Assets', 'route' => 'tech.assets.index'],
        ['label' => 'Edit Asset'],
    ],

    'tech.assets.show' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Assets', 'route' => 'tech.assets.index'],
        ['label' => 'Asset', 'label_from' => 'asset.name'],
    ],

    // Knowledge
    'tech.knowledge.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
    ],

    'tech.knowledge.shelves.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'New Shelf'],
    ],

    'tech.knowledge.shelf' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Shelf', 'label_from' => 'shelf.name'],
    ],

    'tech.knowledge.shelves.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Shelf', 'label_from' => 'shelf.name'],
        ['label' => 'Edit Shelf'],
    ],

    'tech.knowledge.books.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Shelf', 'label_from' => 'shelf.name'],
        ['label' => 'New Book'],
    ],

    'tech.knowledge.book' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Book', 'label_from' => 'book.name'],
    ],

    'tech.knowledge.books.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Book', 'label_from' => 'book.name'],
        ['label' => 'Edit Book'],
    ],

    'tech.knowledge.chapters.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Book', 'label_from' => 'book.name'],
        ['label' => 'New Chapter'],
    ],

    'tech.knowledge.chapters.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Chapter', 'label_from' => 'chapter.name'],
        ['label' => 'Edit Chapter'],
    ],

    'tech.knowledge.books.pages.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Book', 'label_from' => 'book.name'],
        ['label' => 'New Page'],
    ],

    'tech.knowledge.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'New Page'],
    ],

    'tech.knowledge.show' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Page', 'label_from' => 'article.title'],
    ],

    'tech.knowledge.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Knowledge', 'route' => 'tech.knowledge.index'],
        ['label' => 'Page', 'label_from' => 'article.title'],
        ['label' => 'Edit Page'],
    ],

    // Tasks
    'tech.tasks.index' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Tasks', 'route' => 'tech.tasks.index'],
    ],

    'tech.tasks.create' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Tasks', 'route' => 'tech.tasks.index'],
        ['label' => 'New Task'],
    ],

    'tech.tasks.show' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Tasks', 'route' => 'tech.tasks.index'],
        ['label' => 'Task', 'label_from' => 'task.title'],
    ],

    'tech.tasks.edit' => [
        ['label' => 'Dashboard', 'route' => 'tech.dashboard'],
        ['label' => 'Tasks', 'route' => 'tech.tasks.index'],
        ['label' => 'Task', 'label_from' => 'task.title'],
        ['label' => 'Edit Task'],
    ],

];
