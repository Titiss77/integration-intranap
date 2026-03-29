import requests

url = "https://nap.ffessm.fr/request.php"

parametres = {
    "action": "gettop",
    "course": "50SF",
    "bassin": "0",
    "cid": "0",
    "order": "tps",
    "clubid": "0",       # On laisse 0 pour récupérer tous les clubs via l'API
    "saison": "2026",    
    "category": "F",     
    "token": "15e86f224cf5f9737247328e34a456ca" 
}

reponse = requests.get(url, params=parametres)

if reponse.status_code == 200:
    donnees = reponse.json()
    
    print("🏆 Résultats 50SF Femmes en 2026 - Club : PEC 🏆\n")
    
    # 1. On filtre la liste pour ne garder que le club "PEC"
    nageuses_pec = [nageuse for nageuse in donnees if nageuse['club'] == 'PEC']
    
    # 2. On affiche les résultats filtrés
    if nageuses_pec:
        for i, nageuse in enumerate(nageuses_pec):
            print(f"{i+1}. {nageuse['nom']} {nageuse['prenom']} - {nageuse['temps']}")
    else:
        print("Aucune nageuse du PEC n'a été trouvée dans le top 2026 pour cette épreuve.")
else:
    print(f"Erreur de connexion : Code {reponse.status_code}")