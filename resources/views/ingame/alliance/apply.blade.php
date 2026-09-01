@extends('ingame.layouts.main')

@section('content')

    @include('ingame.shared.buddy.bbcode-parser')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    <div id="alliancecomponent" class="maincontent">
        <div id="netz">
            <div id="alliance">
                <div id="inhalt">
                    <div id="planet" class="planet-header">
                        <h2>{{ __('t_ingame.alliance.apply_title') }}</h2>
                        <a class="toggleHeader" href="javascript:void(0);" data-name="alliance">
                            <img alt="" src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="22" width="22">
                        </a>
                    </div>
                    <div class="c-left"></div>
                    <div class="c-right"></div>

                    <div class="alliance_wrapper">
                        <div class="allianceContent">
                            <div id="sendApplication" class="contentbox2">
                                <h3 class="header">{{ __('t_ingame.alliance.apply_heading') }} [{{ $alliance->alliance_tag }}] {{ $alliance->alliance_name }}</h3>

                                <div class="content">
                                    <form id="applicationForm" method="POST" action="{{ route('alliance.apply') }}">
                                        @csrf
                                        <input type="hidden" name="alliance_id" value="{{ $alliance->id }}">

                                        <table id="writeapplication" cellspacing="0" cellpadding="0" style="width:560px">
                                            <tbody>
                                                <tr>
                                                    <td colspan="2">
                                                        <textarea id="allitext" name="message" class="alliancetexts markItUpEditor" maxlength="2000" cols="80" rows="10"></textarea>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="transparent textBeefy" style="width: 120px">
                                                        <span id="c_characters">2000</span> {{ __('t_ingame.alliance.chars_remaining') }}
                                                    </td>
                                                    <td class="transparent textRight">
                                                        <button type="submit" class="btn_blue float_right" id="submitApplication">
                                                            {{ __('t_ingame.alliance.send_application_btn') }}
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="new_footer"></div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            var locaKeys = {
                "bold": @json(__('Bold')),
                "italic": @json(__('Italic')),
                "underline": @json(__('Underline')),
                "stroke": @json(__('Strikethrough')),
                "sub": @json(__('Subscript')),
                "sup": @json(__('Superscript')),
                "fontColor": @json(__('Font colour')),
                "fontSize": @json(__('Font size')),
                "backgroundColor": @json(__('Background colour')),
                "backgroundImage": @json(__('Background image')),
                "tooltip": @json(__('Tool-tip')),
                "alignLeft": @json(__('Left align')),
                "alignCenter": @json(__('Centre align')),
                "alignRight": @json(__('Right align')),
                "alignJustify": @json(__('Justify')),
                "block": @json(__('Break')),
                "code": @json(__('Code')),
                "spoiler": @json(__('Spoiler')),
                "moreopts": @json(__('More Options')),
                "list": @json(__('List')),
                "hr": @json(__('Horizontal line')),
                "picture": @json(__('Image')),
                "link": @json(__('Link')),
                "email": @json(__('Email')),
                "player": @json(__('Player')),
                "item": @json(__('Item')),
                "coordinates": @json(__('Coordinates')),
                "preview": @json(__('Preview')),
                "textPlaceHolder": @json(__('Text...')),
                "playerPlaceHolder": @json(__('Player ID or name')),
                "itemPlaceHolder": @json(__('Item ID')),
                "coordinatePlaceHolder": @json(__('Galaxy:system:position')),
                "charsLeft": @json(__('t_ingame.alliance.chars_remaining')),
                "colorPicker": {
                    "ok": @json(__('Ok')),
                    "cancel": @json(__('Cancel')),
                    "rgbR": @json(__('R')),
                    "rgbG": @json(__('G')),
                    "rgbB": @json(__('B'))
                },
                "backgroundImagePicker": {
                    "ok": @json(__('Ok')),
                    "repeatX": @json(__('Repeat horizontally')),
                    "repeatY": @json(__('Repeat vertically'))
                }
            };

            $(document).ready(function() {
                initBBCodeEditor(
                    locaKeys,
                    {}, // items - empty for now
                    false,
                    '.alliancetexts',
                    2000,
                    false
                );
                $('.alliancetexts').keyup(); // Trigger keyup to set the character counter

                // Form submission
                $('#applicationForm').on('submit', function(e) {
                    var message = $('#allitext').val();

                    if (message.length > 2000) {
                        e.preventDefault();
                        alert(@json(__('t_ingame.alliance.msg_too_long')));
                        return false;
                    }
                });
            });
        </script>
    </div>
@endsection
