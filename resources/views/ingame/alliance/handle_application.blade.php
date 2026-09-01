@include('ingame.shared.buddy.bbcode-parser')

<form method="post" id="allianceHandleApplication">
    <div class="sectioncontent" id="section31" style="display:block;">
        <div class="contentz allycomm">
            <table id="writeapplication">
                <tbody>
                    <tr>
                        <td colspan="2">
                            <span class="content"><h2>{{ __('t_ingame.alliance.application_text') }}</h2></span>
                            <div class="h10"></div>
                            <div class="bborder"></div>
                            <div class="h10"></div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="textLeft">
                            <textarea name="message" class="alliancetexts markItUpEditor"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <input class="sendNewApplication btn_blue float_right" value="{{ __('t_ingame.alliance.send_btn') }}" name="submitMail" data-allianceid="{{ $allianceId }}" id="submitMail" type="button">
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</form>

<script type="text/javascript">
    var urlSendApplication = "{{ route('alliance.apply') }}";
    var urlCancelApplication = "";

    (function($) {
        initBBCodeEditor(
            {
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
            },
            {}, // items - empty for now
            false,
            '.alliancetexts',
            2000
        );

        $('.alliancetexts').keyup(); // This will trigger the keyup-Event for the editor. This will set the remaining Chars Counter to the right value.

        // Handle form submission
        $('.sendNewApplication').on('click', function() {
            var allianceId = $(this).data('allianceid');
            var message = $('.alliancetexts').val();

            if (message.length > 2000) {
                fadeBox(@json(__('t_ingame.alliance.msg_too_long')), true);
                return false;
            }

            $.ajax({
                url: urlSendApplication,
                type: 'POST',
                data: {
                    alliance_id: allianceId,
                    message: message,
                    _token: '{{ csrf_token() }}'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        fadeBox(response.message || @json(__('t_ingame.alliance.msg_apply_success')), false);

                        // Reload alliance page after short delay
                        setTimeout(function() {
                            window.location.href = '{{ route('alliance.index') }}';
                        }, 2000);
                    } else {
                        fadeBox(response.message || @json(__('t_ingame.alliance.msg_apply_error')), true);
                    }
                },
                error: function(xhr) {
                    var errorMessage = @json(__('t_ingame.alliance.msg_error'));

                    if (xhr.responseJSON) {
                        if (xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        } else if (xhr.responseJSON.errors) {
                            // Handle Laravel validation errors
                            var validationErrors = [];
                            $.each(xhr.responseJSON.errors, function(field, messages) {
                                $.each(messages, function(index, message) {
                                    validationErrors.push(message);
                                });
                            });
                            if (validationErrors.length > 0) {
                                errorMessage = validationErrors.join(', ');
                            }
                        }
                    }

                    fadeBox(errorMessage, true);
                }
            });

            return false;
        });
    })(jQuery);
</script>
