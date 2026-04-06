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
saisons = ["2023", "2022"]  # Vous pouvez ajouter d'autres saisons si besoin
liste_epreuves = [
    "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF",
    "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"
]
categories_genre = {"F": "Femmes", "M": "Hommes"}

# --- FONCTIONS D'INSERTION INTELLIGENTES ---
def get_or_create_simple(cursor, table, column, value):
    """Gère l'insertion et la récupération d'ID pour Lieux, Catégories et Épreuves"""
    if not value or value.strip() == "":
        value = "Non renseigné"
    
    cursor.execute(f"INSERT IGNORE INTO {table} ({column}) VALUES (%s)", (value,))
    cursor.execute(f"SELECT id FROM {table} WHERE {column}=%s", (value,))
    return cursor.fetchone()[0]

def get_or_create_nageur(cursor, nom, prenom, genre):
    """Gère l'insertion et la récupération d'ID pour les Nageurs"""
    cursor.execute("INSERT IGNORE INTO nageurs (nom, prenom, genre) VALUES (%s, %s, %s)", (nom, prenom, genre))
    cursor.execute("SELECT id FROM nageurs WHERE nom=%s AND prenom=%s", (nom, prenom))
    return cursor.fetchone()[0]

# --- SCRIPT PRINCIPAL ---
try:
    connexion = mysql.connector.connect(**db_config)
    cursor = connexion.cursor()
    print("✅ Connecté à MySQL avec succès.\n")

    sql_insert_perf = """
        INSERT IGNORE INTO performances (nageur_id, epreuve_id, categorie_id, lieu_id, saison, temps, date_perf)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
    """

    for saison in saisons:
        print(f"📅 === SAISON {saison} ===")
        for epreuve in liste_epreuves:
            print(f"  ⏳ Traitement de l'épreuve : {epreuve}...")
            
            # On récupère ou on crée l'ID de l'épreuve
            epreuve_id = get_or_create_simple(cursor, "epreuves", "nom_epreuve", epreuve)
            
            for cat_code, cat_nom in categories_genre.items():
                parametres = {
                    "action": "gettop", "course": epreuve, "bassin": "0", "cid": "0",
                    "order": "tps", "clubid": "0", "saison": saison, "category": cat_code, "token": token 
                }
                
                reponse = requests.get(url, params=parametres)
                
                if reponse.status_code == 200:
                    donnees = reponse.json()
                    nageurs_filtres = [n for n in donnees if n.get('club') == club_cible]
                    
                    for n in nageurs_filtres:
                        # 1. On récupère TOUS les IDs (Nageur, Catégorie, Lieu)
                        nageur_id = get_or_create_nageur(cursor, n['nom'], n['prenom'], cat_nom)
                        categorie_id = get_or_create_simple(cursor, "categories", "nom_categorie", n.get('categorie'))
                        lieu_id = get_or_create_simple(cursor, "lieux", "nom_lieu", n.get('lieu'))
                        
                        # 2. On insère la performance avec uniquement les IDs
                        valeurs_perf = (
                            nageur_id, epreuve_id, categorie_id, lieu_id, 
                            saison, n['temps'], n.get('date', '')
                        )
                        cursor.execute(sql_insert_perf, valeurs_perf)
                    
                    connexion.commit()
                time.sleep(0.5)

    print("\n🎉 Architecture ultra-optimisée remplie avec succès !")

except Error as e:
    print(f"❌ Erreur MySQL : {e}")
finally:
    if connexion.is_connected():
        cursor.close()
        connexion.close()