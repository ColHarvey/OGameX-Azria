<?php

namespace OGame\Enums;

/**
 * Ce que la ligne d'un compte dit de sa suppression, une fois tenue sous verrou.
 *
 * ## Pourquoi trois etats, et pas un booleen
 *
 * Le controle rendait « en attente ou non », et confondait donc **une ligne absente** avec une ligne
 * presente sans drapeau. Les deux se produisent, et n'appellent pas la meme suite.
 *
 * Apres la validation du drapeau, deux chemins peuvent entrer : la suppression qui vient de le poser
 * et la commande de reprise. L'un efface le compte ; l'autre prend le verrou ensuite, ne trouve plus
 * rien, et poursuivrait avec son modele et sa liste de corps perimes — en effacant des lignes qui ne
 * lui appartiennent plus.
 *
 * - `Absent` : quelqu'un d'autre a fini le travail. Retour sans effet, et c'est ce qui rend la
 *   suppression idempotente.
 * - `NotPending` : la ligne est la, sans drapeau, alors que le retrait vient de l'y poser. Personne
 *   n'a le droit de l'effacer en chemin : c'est un invariant rompu, pas un cas a traiter.
 * - `Pending` : l'etat attendu, et le seul sous lequel le retrait s'execute.
 */
enum AccountDeletionState: string
{
    case Absent = 'absent';

    case NotPending = 'not_pending';

    case Pending = 'pending';
}
