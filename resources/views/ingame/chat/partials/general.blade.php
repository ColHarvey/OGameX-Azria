{{--
    Le chat general : une salle, pas une conversation de plus.

    **Aucun message n'est rendu ici.** La liste part vide et le navigateur la remplit — historique
    et arrivees en direct par le meme gabarit. Rendre l'historique en Blade et les arrivees en
    JavaScript, ce serait deux gabarits pour une meme ligne, et c'est exactement le defaut qui a
    fait lire un nom d'unite en anglais en direct et en francais au rechargement dans le combat.

    Le balisage et les classes sont ceux des conversations existantes (`contentbox`, `largeChat`,
    `chat_msg`, `msg_head`, `msg_content`) ; les decorations d'auteur sont celles du classement
    (`ally-tag`, `playername`, `badgeAdmin`, `honorScore`). Aucune direction graphique nouvelle.
--}}
<div id="generalChat" class="contentbox fleft">
    <h2 class="header">
        <span class="c-right"></span>
        <span class="c-left"></span>
        {{ __('t_ingame.chat.general_title') }}
    </h2>
    <div class="content clearfix">
        <div class="largeChatContainer chat_bar_list">
            <ul id="generalChatList" class="chat clearfix largeChat">
                <li class="chat_msg js_generalChatPlaceholder">
                    <span class="msg_content">{{ __('t_ingame.chat.general_empty') }}</span>
                </li>
            </ul>
        </div>
        <div id="generalChatNotice" class="overmark" style="display: none;"></div>
        <div class="editor_wrap">
            <div>
                <textarea id="generalChatText" name="text" class="new_msg_textarea"
                          maxlength="2000" placeholder="{{ __('t_ingame.chat.general_placeholder') }}"></textarea>
            </div>
            <a href="javascript:void(0);" class="btn_blue fright" id="generalChatSend">{{ __('t_ingame.chat.submit') }}</a>
        </div>
    </div>
    <div class="footer">
        <div class="c-right"></div>
        <div class="c-left"></div>
    </div>
</div>

<script type="text/javascript">
    {{-- Les valeurs que le module lit. Declarees avant lui, comme le veut le contrat : le bundle
         est charge dans l'en-tete et s'execute avant le corps de la page. --}}
    var generalChatIgnoredIds = @json($generalIgnoredPlayerIds ?? []);
    var generalChatLoca = {
        empty: @json(__('t_ingame.chat.general_empty')),
        tooMany: @json(__('t_ingame.chat.general_too_many')),
        sendFailed: @json(__('t_ingame.chat.general_send_failed')),
        disconnected: @json(__('t_ingame.chat.general_disconnected')),
        adminBadge: @json(__('t_ingame.highscore.badge_admin')),
        honourPoints: @json(__('t_ingame.highscore.honour_points')),
    };
</script>
