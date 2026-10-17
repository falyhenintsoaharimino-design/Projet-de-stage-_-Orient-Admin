# CLAUDE.md

@AGENTS.md

## Consignes pour ce projet (Orient-Admin)

- Répondre en français. Changements minimaux et ciblés, jamais de réécriture complète.
- Au début d'une discussion : lire README.md, AGENTS.md (section « Projet Orient-Admin »)
  et, si l'étape le demande, les documents de `.document de references/`.
- Livrer chaque étape backend sous forme de patch (`git apply`) + script de recette
  `scripts/smoke-XXX.sh`, avec l'explication simple de où placer les fichiers.
  Vérifier la syntaxe PHP (`php -l`) et que le patch s'applique sur un clone propre.
- Tester en ligne de commande, pas sur le front (pas encore au point).
- Terminer chaque étape par : `doctrine:schema:validate` OK, commit, push, puis mettre
  à jour la section « Avancement » d'AGENTS.md.
- Économiser les tokens : prévenir quand la discussion devient trop longue et proposer
  un résumé court pour repartir dans une nouvelle conversation.
- Ne jamais écrire de secrets dans le dépôt (vraies valeurs dans `.env.local`).
