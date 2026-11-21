# OPNsense AI Security Wizard : Guide pratique

**Brouillon v0.1 — Daniel Freeman (danfreeus702)**
*Companion du plugin open source : https://github.com/danfreeus702/os-ai-opensense*

> Ceci est un brouillon. Les chapitres 1–2 sont terminés ; les autres sont
> esquissés et seront rédigés à partir des comptes rendus de tests du projet.

---

## Table des matières

1. Pourquoi un tel assistant existe
2. Démarrage rapide : installer et appliquer pour la première fois
3. Les trois niveaux, expliqués
4. DNS chiffré sur OPNsense 26.7 (pourquoi DoT et pas DoH)
5. L'assistant IA : documentation officielle et matériel
6. Lire les différences en langage clair
7. Retour arrière : instantanés et annulation en un clic
8. Utiliser l'assistant hors ligne
9. Vérification : comment le plugin a été testé
10. Dépannage et limites réelles
11. Annexe A — actions configd et emplacements des fichiers
12. Annexe B — mise à jour et désinstallation

---

## Chapitre 1 — Pourquoi un tel assistant existe

Un pare-feu neuf est une page blanche. C'est sa force et sa faiblesse. Il peut
tout faire, mais la personne devant l'interface doit décider *quoi*, *où* et
*dans quel ordre*. Sur OPNsense, cela signifie parcourir plus de 150 liens de
menu entre le pare-feu, le DNS, le VPN, les services et le système. La plupart
des gens laissent la machine à moitié configurée ou copient des réglages qu'ils
ne comprennent pas.

L'assistant de sécurité pose une question différente : *quel niveau de
protection souhaitez-vous ?* Il produit ensuite un plan que vous pouvez
examiner. Vous voyez toujours chaque modification avant qu'elle ne s'applique,
y compris le chemin exact du menu qui permettrait de la faire à la main. L'agent
n'est pas une boîte noire : c'est un raccourci avec une trace écrite.

### Règles de conception

Quatre règles ont guidé le projet :

1. **Rien ne change sans confirmation.** Chaque action modificatrice exige un
   `confirm=yes` explicite et l'interface affiche d'abord les différences en
   langage clair.
2. **Aucun secret dans config.xml.** La clé du modèle cloud réside dans un
   fichier réservé à root, `/conf/aiopensense.key` (mode 600), afin qu'elle ne
   soit pas copiée dans les sauvegardes.
3. **Chaque application est réversible.** Un instantané horodaté de la
   configuration est écrit avant la première modification, et un seul bouton
   «Undo last change» le restaure.
4. **Modèle cloud uniquement.** Aucun modèle local n'est fourni : le plugin
   fonctionne donc sur les petits appareils à mémoire limitée.

### À qui s'adresse-t-il

- Aux particuliers qui veulent une base sûre en quelques minutes.
- Aux petites structures sans ingénieur réseau dédié.
- À ceux qui apprennent OPNsense et veulent voir *à quoi ressemble une
  configuration correcte* avant de la faire manuellement.

Si vous gérez un déploiement complexe avec plusieurs WAN, du BGP et une pile IDS
complète, cet assistant ne remplace pas votre conception : c'est un point de
départ propre.

---

## Chapitre 2 — Démarrage rapide : installer et appliquer pour la première fois

### Prérequis

- **OPNsense 26.7** ou supérieur (testé sur 26.7.9_3).
- Un accès internet depuis le pare-feu pour télécharger les listes de blocage
  et interroger le modèle cloud.
- Une clé API OpenRouter (principal) ou NVIDIA NIM (secours).

### Installer le paquet

Téléchargez `os-ai-opensense-0.1_1.pkg` depuis la page des versions GitHub,
copiez-le sur le pare-feu et installez-le en root :

```sh
pkg add /tmp/os-ai-opensense-0.1_1.pkg
```

Les hooks d'installation redémarrent configd et chargent le nouveau modèle
d'affichage. Vérifiez avec :

```sh
pkg info os-ai-opensense
```

### Ajouter la clé API (root uniquement)

```sh
printf '%s\n' 'your-key-here' > /conf/aiopensense.key
chmod 600 /conf/aiopensense.key
```

Ne placez jamais la clé dans `config.xml` : ce fichier se retrouve dans les
sauvegardes et les bundles d'assistance.

### Ouvrir l'assistant

Dans l'interface web, choisissez **OPNsense AI → Security Wizard**. Trois
cartes s'affichent. Sélectionnez un niveau ; le panneau du plan s'ouvre avec la
liste des étapes. En dessous se trouve le tableau **What will change**, en
quatre colonnes :

- la modification,
- sa valeur actuelle,
- sa valeur après application,
- le chemin exact de l'interface où se trouve ce réglage.

Lisez ce tableau. Si quelque chose vous semble bizarre, fermez la page : rien
n'a encore été modifié.

### Appliquer

Cochez les options (le nœud de sortie Tailscale n'existe qu'au niveau 3), puis
cliquez sur **Apply selected level**. La boîte de confirmation précise qu'un
instantané de retour arrière sera enregistré automatiquement. Après quelques
secondes, une coche verte apparaît pour chaque étape. Le panneau de résultat
affiche également la note concernant l'instantané.

### Vérifier

```sh
tail -20 /var/log/aiopensense-security.log
pfctl -sr | grep -E 'domain|https|domain-s'
```

Les règles DNS et HTTPS de l'assistant doivent figurer parmi les règles pf
actives, et une ligne `OK` doit apparaître pour chaque action dans le journal
d'audit.

### Annuler

Pour revenir en arrière, cliquez sur **Undo last change** dans le panneau de
résultat. Le contrôleur restaure le dernier instantané et recharge les règles du
pare-feu. Vérifiez le niveau appliqué :

```sh
grep -o '<security_level>[0-9]*</security_level>' /conf/config.xml
```

---

*Chapitres 3–12 : esquissés ci-dessus. Prochains à rédiger : « Les trois
niveaux, expliqués » et « DNS chiffré sur OPNsense 26.7 (pourquoi DoT et pas
DoH) », à partir de NOTES.md et des constantes RESOLVERS/BLOCKLISTS du modèle.*
