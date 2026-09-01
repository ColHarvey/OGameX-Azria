@include('ingame.shared.buddy.bbcode-parser')

{{-- Alliance Communication Tab --}}
<div class="allianceContent">
    <form method="post" id="allianceBroadCast" autocomplete="off" action="javascript:void(0);" onsubmit="return false;">
        @csrf
        <div class="sectioncontent" id="section31" style="display:block;">
            <div class="contentz allycomm">
                <table id="broadcastTable">
                    <tbody>
                        <tr>
                            <td class="desc textBeefy">{{ __('t_ingame.alliance.addressee') }}</td>
                            <td>
                                <select class="dropdownInitialized" name="empfaenger[]" multiple id="selectNew" style="width: 310px;">
                                    <option value="-1" id="-1" selected>{{ __('t_ingame.alliance.all_players') }}</option>
                                    @foreach($ranks as $rank)
                                        <option value="{{ $rank->id }}" id="{{ $rank->id }}">{{ __('t_ingame.alliance.only_rank') }} {{ $rank->rank_name }}</option>
                                    @endforeach
                                </select>
                                <script language="javascript">
                                    jQuery("#selectNew").select2({
                                        tags: true
                                    });
                                </script>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" class="textLeft">
                                <textarea name="text" class="alliancetexts"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <input class="btn_blue float_right" value="{{ __('t_ingame.alliance.send_btn') }}" name="submitMail" id="submitMail" type="button">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <script type="text/javascript">
        var urlSend = "{{ route('alliance.action') }}?action=send_broadcast&asJson=1";
        (function($) {
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

            initBBCodeEditor(
                locaKeys,
                {}, // items - empty for now
                false,
                '.alliancetexts',
                2000
            );
            $('.alliancetexts').keyup(); //This will trigger the keyup-Event for the editor. This will set the remaining Chars Counter to the right value.

            // Note: Click handler for #submitMail is already bound in ingame.js
            // via Alliance.prototype.onFormClickBroadcastButton
        })(jQuery);
    </script>
</div>
