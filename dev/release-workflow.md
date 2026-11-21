# Release Workflow — SEOGhost Connector

Ce document explique comment publier une nouvelle version du plugin WordPress et ce que cela déclenche chez les clients déjà installés.

---

## 1. Publier une nouvelle version (ex : `1.0.3`)

> Un simple `git push` sur `main` (pour modifier le `README.md` ou de la doc) **ne déclenche aucune mise à jour** chez les clients.  
> Seul un tag au format `v*` lance la création d’une release.

### Étapes dans `seoghost-connector/seoghost-connector.php`
1. Mettre à jour le numéro de version à **2 endroits** en haut du fichier :
   - Dans l’en-tête du plugin : `* Version: 1.0.3`
   - Dans la constante PHP : `const VERSION = '1.0.3';`

### Étapes dans le terminal
2. Commiter et pousser le code sur `main` :

```bash
git add .
git commit -m "v1.0.3: description courte du changement"
git push
```

3. Créer le tag correspondant **après** le commit et le pousser sur GitHub :

```bash
git tag v1.0.3
git push origin v1.0.3
```

---

## 2. Ce qui se passe automatiquement côté GitHub

Dès que le tag `v1.0.3` est poussé, la GitHub Action `.github/workflows/release.yml` :
1. Compresse le dossier `seoghost-connector/` dans `seoghost-connector.zip` (en excluant `.DS_Store`).
2. Crée la Release GitHub `v1.0.3` avec le changelog automatique.
3. Attache `seoghost-connector.zip` comme Asset officiel de la Release.

*(Prérequis : le repo `n3twrks/seoghost-wordpress-plugin` doit rester **Public** pour que les WordPress clients puissent lire la Release sans authentification.)*

---

## 3. Ce qui se passe chez les clients déjà équipés

* Tous les sites WordPress ayant le plugin en version `>= 1.0.2` interrogent l’API GitHub (`releases/latest`) avec un **cache de 6 heures**.
* Dès que le cache expire (ou immédiatement si un admin clique sur **Tableau de bord > Mises à jour > Vérifier à nouveau** dans WordPress) :
  * Une mise à jour **« Mettre à jour maintenant »** apparaît dans **Extensions > Extensions installées**.
  * Le client (ou toi) met à jour en **1 clic**, sans perdre les réglages (`api_key`, `site_slug`, catégorie, auteur).
  * Si les **mises à jour automatiques** sont activées sur l’extension dans WordPress, la mise à jour se fait toute seule en arrière-plan.

---

## 4. Installer le plugin sur un nouveau client WordPress

Ne pas zipper le dossier complet du repo ni télécharger `Source code (zip)` sur GitHub.
1. Aller sur `https://github.com/n3twrks/seoghost-wordpress-plugin/releases/latest`.
2. Télécharger l’asset **`seoghost-connector.zip`**.
3. Dans le WordPress du client : **Extensions > Ajouter > Téléverser une extension**.
4. Activer puis configurer dans **Réglages > SEOGhost**.

---

## 5. Dépannage : si le tag a été créé avant le commit

Si le tag `v1.0.3` a été poussé trop tôt (avant de commit les changements) :

```bash
git push --delete origin v1.0.3
git tag -f v1.0.3
git push origin v1.0.3
```