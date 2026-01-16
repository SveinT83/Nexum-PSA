<?php

namespace App\Service\SideBarMenus;

class ClientsMenu
{
    public function ClientsMenu(): array
    {
        return [
            ['name' => 'Clients', 'route' => 'tech.clients.index'],
            ['name' => 'Sites', 'route' => 'tech.clients.sites.index'],
            ['name' => 'Users', 'route' => 'tech.clients.users.index'],
        ];
    }
}
