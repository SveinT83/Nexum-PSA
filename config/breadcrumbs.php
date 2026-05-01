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
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
    ],

    // Templates Management - Main Hub
    'admin.system.templatesManagement.index' => [
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
    ],

    // Documentation Templates - List
    'admin.system.templatesManagement.doc.index' => [
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
        ['label' => 'Documentation'],
    ],

    // Documentation Templates - Create/Edit Form
    'admin.system.templatesManagement.doc.create' => [
        ['label' => 'Admin', 'route' => 'tech.admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
        ['label' => 'Documentation', 'route' => 'tech.admin.system.templatesManagement.doc.index'],
        ['label' => 'Form'],
    ],

    'admin.system.templatesManagement.doc.edit' => [
        ['label' => 'Admin', 'route' => 'admin.index'],
        ['label' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.index'],
        ['label' => 'Documentation', 'route' => 'tech.admin.system.templatesManagement.doc.index'],
        ['label' => 'Form'],
    ],

];
