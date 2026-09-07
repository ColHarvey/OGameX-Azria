<?php

namespace OGame\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * La demande de lien de reinitialisation repond toujours la meme chose.
 *
 * ## Le defaut ferme ici
 *
 * Fortify distingue trois issues a `sendResetLink()` : le lien est parti, l'adresse est inconnue,
 * ou la demande est trop rapprochee de la precedente. Il les rendait telles quelles — une adresse
 * inconnue recevait « We can't find a user with that email address. », une adresse connue n'en
 * recevait aucune.
 *
 * **N'importe qui pouvait donc savoir si un compte existe** en soumettant une adresse au formulaire
 * de mot de passe oublie, sans rien connaitre du mot de passe. Sur un jeu ou les pseudonymes sont
 * publics, c'est le premier pas d'une attaque ciblee.
 *
 * ## Pourquoi la limitation est masquee elle aussi
 *
 * Masquer la seule adresse inconnue ne suffit pas. La limitation ne s'applique qu'aux comptes
 * reels — c'est leur jeton precedent qui la declenche —, donc « trop de demandes » aurait continue
 * a dire « ce compte existe », simplement en deux clics au lieu d'un. Le canal se referme entierement
 * ou pas du tout.
 *
 * La limitation continue de **fonctionner** : aucun courriel supplementaire ne part. Elle n'est plus
 * **annoncee**, voila tout.
 *
 * ## Ce que la phrase promet, et ce qu'elle ne promet pas
 *
 * « Si un compte correspond a cette adresse, vous recevrez un courriel » est vraie dans les trois
 * cas. Annoncer un envoi effectif serait faux pour une adresse inconnue — et ce mensonge-la se
 * detecterait aussi.
 *
 * ## Ce qui n'est pas ferme
 *
 * Le **temps de reponse**. Envoyer un courriel prend plus longtemps que ne rien faire, et la
 * difference reste mesurable par qui la cherche. La fermer demanderait de sortir l'envoi de la
 * requete — un travail distinct, qui n'est pas fait ici et qu'il serait faux de laisser croire fait.
 */
final class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    /**
     * L'issue reelle, gardee pour le journal du serveur — jamais pour le joueur.
     */
    public function __construct(private readonly string $status)
    {
    }

    /**
     * La meme reponse, quelle que soit l'issue.
     *
     * @param Request $request
     * @return Response
     */
    public function toResponse($request)
    {
        $phrase = trans('t_recovery.sent');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $phrase], 200)
            : back()->with('status', $phrase);
    }

    /**
     * L'issue que Fortify a rendue.
     *
     * Elle ne sort pas d'ici : elle existe pour qu'un diagnostic reste possible cote serveur sans
     * que le joueur puisse la lire.
     */
    public function actualStatus(): string
    {
        return $this->status;
    }
}
