import json
import pandas as pd # Assurez-vous d'avoir installé pandas (pip install pandas)

# Le nom du fichier que nous avons généré à l'étape précédente
nom_fichier_json = "profils_nageurs_PEC_2026.json"

try:
    # 1. Ouvrir et lire le fichier JSON
    with open(nom_fichier_json, 'r', encoding='utf-8') as fichier:
        profils = json.load(fichier)

    # 2. "Aplatir" les données (Transformer le JSON imbriqué en une liste simple)
    lignes_tableau = []
    
    for nom_complet, infos in profils.items():
        # Pour chaque nageur, on parcourt toutes ses performances
        for perf in infos.get("performances", []):
            # On crée une ligne de tableau qui regroupe l'info du nageur ET sa performance
            lignes_tableau.append({
                "Nom": infos["nom"],
                "Prénom": infos["prenom"],
                "Sexe": infos["genre"],
                "Catégorie": infos["categorie"],
                "Épreuve": perf["epreuve"],
                "Temps": perf["temps"],
                "Date": perf["date"],
                "Lieu": perf["lieu"]
            })

    # 3. Créer le tableau avec Pandas (DataFrame)
    df = pd.DataFrame(lignes_tableau)

    # Trier le tableau (par exemple : par Nom, puis par Épreuve)
    df = df.sort_values(by=["Nom", "Épreuve"])

    # Afficher un aperçu dans la console (les 20 premières lignes)
    print(f"📊 Aperçu du tableau pour le club PEC :\n")
    print(df.head(20).to_string(index=False))
    print(f"\n... (Total : {len(df)} performances trouvées)")

    # 4. Exporter le tableau en fichier CSV (lisible par Excel, avec séparateur point-virgule)
    nom_fichier_csv = "tableau_performances_PEC_2026.csv"
    df.to_csv(nom_fichier_csv, index=False, sep=";", encoding="utf-8-sig")
    
    print(f"\n✅ Le tableau a été exporté avec succès dans le fichier : '{nom_fichier_csv}'")
    
    # Astuce : Si vous préférez un vrai fichier Excel (.xlsx) au lieu d'un CSV, 
    # installez openpyxl (pip install openpyxl) et décommentez la ligne suivante :
    # df.to_excel("tableau_performances_PEC_2026.xlsx", index=False)

except FileNotFoundError:
    print(f"❌ Erreur : Le fichier '{nom_fichier_json}' est introuvable. Avez-vous bien exécuté le script de récupération avant ?")
except Exception as e:
    print(f"❌ Une erreur s'est produite : {e}")