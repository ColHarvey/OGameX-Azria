@php /** @var OGame\Services\PlayerService $currentPlayer */ @endphp
<div id="adminbar">
    <style>
        /* La barre s'adapte a son contenu. Elle etait figee a 32px : au septieme lien,
           la liste passait a la ligne et debordait par-dessus le jeu. Une barre de menu ne
           doit pas supposer combien d'entrees elle contiendra. */
        #adminbar {
            background: transparent url('/img/admin/admin-menu-bg.jpg') repeat-x;
            font: normal 11px Tahoma, Arial, Helvetica, sans-serif;
            height: 32px;
            left: 0;
            padding: 0;
            text-align: center;
            top: 0;
            width: 100%;
            z-index: 3000;
        }

        #adminbar #mmoContent {
            height: 32px;
            margin: 0 auto;
            width: 990px;
            position: relative;
            /* Centrage vertical du titre et des liens dans la bande orange. L ancienne mise
               en page les posait par une marge fixe de 8px, devinee pour une seule ligne de
               texte : le moindre ecart de hauteur les faisait deborder sous l image de fond. */
            display: flex;
            align-items: center;
            justify-content: space-between;
            overflow: hidden;
        }

        #adminbar #adminLogo {
            float: none;
            display: block;
            height: auto;
            width: auto;
            padding: 5px 10px;
            padding-left: 0;
            font-size: 14px;
            color: #f48406 !important;
            font-weight: bold;
        }

        #adminbar #adminLogo span {
            font-size: 18px;
            vertical-align: middle;
        }

        #adminbar ul {
            list-style: none;
            margin: 0;
            padding: 0;
            float: none;
        }

        #adminbar ul li {
            display: inline;
            margin-right: 10px;
        }

        #adminbar ul li a {
            color: #fff;
            background-color: #333;
            padding: 3px 10px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 11px;
        }

        #adminbar ul li a:hover, #adminbar ul li a.active  {
            background-color: #555;
        }
    </style>
    <div id="mmoContent">
        <div id="adminLogo">
            @if(!empty($isImpersonating))
                {{ __('Masquerading as user') }}
            @else
                @lang('Server admin')
            @endif
        </div>
        @if(!empty($isImpersonating) && !empty($impersonateLeaveUrl))
            <ul>
                <li>
                    <a href="{{ $impersonateLeaveUrl }}" class="active">
                        {{ __('Exit masquerade') }}
                    </a>
                </li>
            </ul>
        @else
            <ul>
                <li><a class="{{(Request::is('admin/developer-shortcuts') ? 'active' : '') }}" href="{{ route('admin.developershortcuts.index') }}">@lang('Developer shortcuts')</a></li>
                <li><a class="{{(Request::is('admin/server-settings') ? 'active' : '') }}" href="{{ route('admin.serversettings.index') }}">@lang('Server settings')</a></li>
                <li><a class="{{(Request::is('admin/fleet-timing*') ? 'active' : '') }}" href="{{ route('admin.fleettiming.index') }}">@lang('Fleet Timing')</a></li>
                <li><a class="{{(Request::is('admin/rules') ? 'active' : '') }}" href="{{ route('admin.rules.index') }}">@lang('Rules & Legal')</a></li>
                <li><a class="{{(Request::is('admin/announcement') ? 'active' : '') }}" href="{{ route('admin.announcement.index') }}">@lang('Annonces')</a></li>
                <li><a class="{{(Request::is('admin/event') ? 'active' : '') }}" href="{{ route('admin.event.index') }}">@lang('Event')</a></li>
                <li><a class="{{(Request::is('admin/server-administration*') ? 'active' : '') }}" href="{{ route('admin.server-administration.index') }}">@lang('Server Administration')</a></li>
            </ul>
        @endif
    </div>
</div>
