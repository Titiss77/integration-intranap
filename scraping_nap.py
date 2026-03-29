import requests
import json
import time

url = "https://nap.ffessm.fr/request.php"
token = "15e86f224cf5f9737247328e34a456ca" # N'oubliez pas de mettre à jour le token si besoin
club_cible = "PEC"
saison_cible = "2026"

epreuves = [
    "25SF", "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF", "1850SF",
    "25AP", "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"
]

categories = {"F": "Femmes", "M": "Hommes"}

# Notre nouvelle structure de données : un dictionnaire pour regrouper par nageur
profils_nageurs = {}

print(f"🚀 Lancement de la récupération pour le club {club_cible} (Saison {saison_cible})\n")

for epreuve in epreuves:
    print(f"⏳ Récupération des données pour l'épreuve : {epreuve}...")
    
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
            try:
                donnees = reponse.json()
                
                # Filtre sur le club
                nageurs_filtres = [n for n in donnees if n.get('club') == club_cible]
                
                for n in nageurs_filtres:
                    # On crée un identifiant unique pour le nageur (Nom + Prénom)
                    nom_complet = f"{n['nom']} {n['prenom']}"
                    
                    # Si c'est la première fois qu'on croise ce nageur, on crée sa "fiche"
                    if nom_complet not in profils_nageurs:
                        profils_nageurs[nom_complet] = {
                            "nom": n['nom'],
                            "prenom": n['prenom'],
                            "genre": cat_nom,
                            "naissance": n.get('naissance', ''),
                            "categorie": n.get('categorie', ''),
                            "performances": [] # On prépare une liste vide pour ses chronos
                        }
                    
                    # On ajoute la performance en cours à la fiche du nageur
                    profils_nageurs[nom_complet]["performances"].append({
                        "epreuve": epreuve,
                        "temps": n['temps'],
                        "date": n.get('date', ''),
                        "lieu": n.get('lieu', '')
                    })
                    
            except json.JSONDecodeError:
                print(f"  ⚠️ Erreur de lecture des données pour {epreuve} ({cat_nom})")
        else:
            print(f"  ❌ Erreur {reponse.status_code} pour {epreuve} ({cat_nom})")
        
        # Petite pause pour ne pas saturer le serveur
        time.sleep(0.5)

# Sauvegarde des profils dans un fichier JSON
if profils_nageurs:
    nom_fichier = f"profils_nageurs_{club_cible}_{saison_cible}.json"
    
    with open(nom_fichier, 'w', encoding='utf-8') as fichier:
        json.dump(profils_nageurs, fichier, ensure_ascii=False, indent=4)
        
    print(f"\n✅ Succès ! Les profils de {len(profils_nageurs)} nageurs différents ont été sauvegardés dans '{nom_fichier}'.")
else:
    print(f"\n⚠️ Aucun nageur trouvé pour le {club_cible} en {saison_cible}.")