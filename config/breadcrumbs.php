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

];
