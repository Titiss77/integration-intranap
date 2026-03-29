import requests
import time
import mysql.connector
from mysql.connector import Error

# --- CONFIGURATION BDD ---
db_config = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'ffessm_nap'
}

# --- CONFIGURATION API ---
url = "https://nap.ffessm.fr/request.php"
token = "15e86f224cf5f9737247328e34a456ca"
club_cible = "PEC"

# AJOUT DES ANNÉES : On va boucler sur ces saisons
saisons = ["2026", "2025", "2024"] 

epreuves = [
    "25SF", "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF", "1850SF",
    "25AP", "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"
]
categories = {"F": "Femmes", "M": "Hommes"}

def get_or_create_nageur(cursor, nom, prenom, genre):
    """Cherche l'ID du nageur, ou le crée s'il n'existe pas encore"""
    # On tente de l'insérer (ignoré s'il existe déjà grâce à la clé unique)
    cursor.execute("INSERT IGNORE INTO nageurs (nom, prenom, genre) VALUES (%s, %s, %s)", (nom, prenom, genre))
    # On récupère son ID
    cursor.execute("SELECT id FROM nageurs WHERE nom=%s AND prenom=%s", (nom, prenom))
    result = cursor.fetchone()
    return result[0] if result else None

try:
    connexion = mysql.connector.connect(**db_config)
    cursor = connexion.cursor()
    print("✅ Connecté à MySQL avec succès.\n")

    sql_insert_perf = """
        INSERT IGNORE INTO performances (nageur_id, saison, epreuve, categorie, temps, date_perf, lieu)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
    """

    for saison in saisons:
        print(f"📅 === SAISON {saison} ===")
        for epreuve in epreuves:
            print(f"  ⏳ Traitement de l'épreuve : {epreuve}...")
            
            for cat_code, cat_nom in categories.items():
                parametres = {
                    "action": "gettop", "course": epreuve, "bassin": "0", "cid": "0",
                    "order": "tps", "clubid": "0", "saison": saison, "category": cat_code, "token": token 
                }
                
                reponse = requests.get(url, params=parametres)
                
                if reponse.status_code == 200:
                    donnees = reponse.json()
                    nageurs_filtres = [n for n in donnees if n.get('club') == club_cible]
                    
                    for n in nageurs_filtres:
                        # 1. Gestion du Nageur (récupération de son ID relationnel)
                        nageur_id = get_or_create_nageur(cursor, n['nom'], n['prenom'], cat_nom)
                        
                        # 2. Ajout de la performance reliée à ce nageur
                        if nageur_id:
                            valeurs_perf = (
                                nageur_id, saison, epreuve, n.get('categorie', ''),
                                n['temps'], n.get('date', ''), n.get('lieu', '')
                            )
                            cursor.execute(sql_insert_perf, valeurs_perf)
                    
                    connexion.commit() # Sauvegarde en base
                time.sleep(0.5) # Pause pour préserver le serveur de la fédé

    print("\n🎉 Récupération et structuration relationnelle terminées avec succès !")

except Error as e:
    print(f"❌ Erreur MySQL : {e}")
finally:
    if connexion.is_connected():
        cursor.close()
        connexion.close()