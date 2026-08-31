@extends('ingame.layouts.main')

@section('content')
    <div id="eventscomponent" class="maincontent">
        <div id="content">
            <div id="inhalt">
                <div id="planet" class="planet-header">
                    <div id="header_text">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                </div>
                <div class="c-left"></div>
                <div class="c-right"></div>

                <div id="buttonz">
                    <div class="header">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                    <div class="content">
                        <p style="color:#8fa7bd; padding:20px;">{{ __('t_ingame.events.closed') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
