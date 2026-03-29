import requests

url = "https://nap.ffessm.fr/request.php"
token = "15e86f224cf5f9737247328e34a456ca"
club_cible = "PEC"
saison_cible = "2026"
epreuve = "50AP"

# On va boucler sur les Femmes (F) et les Hommes (M)
categories = {"F": "Femmes", "M": "Hommes"}
tous_les_nageurs_pec = []

for cat_code, cat_nom in categories.items():
    parametres = {
        "action": "gettop",
        "course": epreuve,
        "bassin": "0",
        "cid": "0",
        "order": "tps",
        "clubid": "0",
        "saison": saison_cible,    
        "category": cat_code,     
        "token": token 
    }
    
    reponse = requests.get(url, params=parametres)
    
    if reponse.status_code == 200:
        donnees = reponse.json()
        
        # On filtre la liste globale pour ne garder que le PEC
        nageurs_filtres = [n for n in donnees if n.get('club') == club_cible]
        
        # On ajoute l'information du genre pour y voir plus clair, puis on stocke
        for n in nageurs_filtres:
            n['genre'] = cat_nom
            tous_les_nageurs_pec.append(n)
    else:
        print(f"Erreur de connexion lors de la requête pour les {cat_nom}.")

# Affichage du résultat final
print(f"🏆 Tous les nageurs du {club_cible} sur {epreuve} en {saison_cible} 🏆\n")

if tous_les_nageurs_pec:
    # On peut même retrier la liste globale par temps (ordre alphabétique du chrono)
    tous_les_nageurs_pec_tries = sorted(tous_les_nageurs_pec, key=lambda x: x['temps'])
    
    for i, nageur in enumerate(tous_les_nageurs_pec_tries, 1):
        print(f"{i}. {nageur['nom']} {nageur['prenom']} ({nageur['genre']}) - {nageur['temps']} - {nageur['lieu']}")
else:
    print(f"Aucun nageur trouvé pour le {club_cible}.")