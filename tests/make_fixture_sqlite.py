#!/usr/bin/env python3
from __future__ import annotations

import math
import sqlite3
import sys
from pathlib import Path

OUT = Path(sys.argv[1] if len(sys.argv) > 1 else Path(__file__).resolve().parents[1] / "data-private")
OUT.mkdir(parents=True, exist_ok=True)


def kv_tables(con: sqlite3.Connection, settings: dict, schema: dict):
    con.execute("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID")
    con.executemany("INSERT INTO settings(key,value) VALUES (?,?)", [(k, str(v)) for k, v in settings.items()])
    con.execute("CREATE TABLE schema_info (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID")
    con.executemany("INSERT INTO schema_info(key,value) VALUES (?,?)", [(k, str(v)) for k, v in schema.items()])


def build_diurnal(path: Path):
    path.unlink(missing_ok=True)
    con = sqlite3.connect(path)
    kv_tables(con, {
        "default_gene": "Dbp", "default_cluster": "L23", "default_genotype": "NTG",
        "x_axis_label": "Zeitgeber Time (double plotted)",
        "y_axis_label": "log2 Normalized mRNA Expression",
        "spatial_legend_label": "log2(normalized counts)",
        "allen_atlas_id": "2", "allen_atlas_plate_ordinal": "7",
    }, {"schema_name": "fixture_diurnal", "schema_version": "1"})
    con.execute("CREATE TABLE genes (gene_id INTEGER PRIMARY KEY, symbol TEXT NOT NULL UNIQUE COLLATE NOCASE, symbol_upper TEXT NOT NULL UNIQUE, gene_prefix TEXT NOT NULL, sort_order INTEGER NOT NULL)")
    genes = [(1,"Dbp","DBP","D",1),(2,"Lct","LCT","L",2),(3,"Isl1","ISL1","I",3)]
    con.executemany("INSERT INTO genes VALUES (?,?,?,?,?)", genes)
    con.execute("CREATE TABLE clusters (cluster_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER NOT NULL, color TEXT)")
    clusters=[(1,"L23","Cortex Layer 2/3",1,"#72A075"),(2,"DGsg","Dentate Gyrus granule layer",2,"#468FCD"),(3,"CA1","CA1",3,"#519AC4")]
    con.executemany("INSERT INTO clusters VALUES (?,?,?,?,?)",clusters)
    con.execute("CREATE TABLE ages (age_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER NOT NULL, color TEXT)")
    con.executemany("INSERT INTO ages VALUES (?,?,?,?,?)",[(1,"7 months","7 months",1,"#FFFF99"),(2,"14 months","14 months",2,"#D8B365")])
    con.execute("CREATE TABLE sexes (sex_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER NOT NULL, color TEXT)")
    con.executemany("INSERT INTO sexes VALUES (?,?,?,?,?)",[(1,"F","F",1,"#E6A0C4"),(2,"M","M",2,"#C6CDF7")])
    con.execute("CREATE TABLE genotypes (genotype_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER NOT NULL, color TEXT)")
    con.executemany("INSERT INTO genotypes VALUES (?,?,?,?,?)",[(1,"NTG","NTG",1,"#0072B5"),(2,"APP23","APP23",2,"#BC3C29")])
    con.execute("CREATE TABLE samples (sample_id INTEGER PRIMARY KEY, sample_key TEXT NOT NULL UNIQUE, cluster_id INTEGER, age_id INTEGER, sex_id INTEGER, genotype_id INTEGER, time_label TEXT, zt REAL)")
    con.execute("CREATE TABLE expression (gene_id INTEGER NOT NULL, sample_id INTEGER NOT NULL, value REAL NOT NULL, PRIMARY KEY(gene_id,sample_id)) WITHOUT ROWID")
    con.execute("CREATE TABLE model_coefficients (gene_id INTEGER NOT NULL, cluster_id INTEGER NOT NULL, age_id INTEGER NOT NULL, sex_id INTEGER NOT NULL, genotype_id INTEGER NOT NULL, n_samples INTEGER NOT NULL, intercept REAL NOT NULL, sin_coef REAL NOT NULL, cos_coef REAL NOT NULL, PRIMARY KEY(gene_id,cluster_id,age_id,sex_id,genotype_id)) WITHOUT ROWID")
    sid=1
    samples=[]
    expr=[]
    times=[0,4,8,12,16,20]
    for cluster_id in (1,2,3):
      for age_id in (1,2):
       for sex_id in (1,2):
        for gt_id in (1,2):
         for rep,zt in enumerate(times):
          key=f"s{sid:04d}"
          samples.append((sid,key,cluster_id,age_id,sex_id,gt_id,f"ZT{zt}",float(zt)))
          for gene_id in (1,2,3):
           base=6 + gene_id*0.7 + cluster_id*0.15 + (age_id-1)*0.2 + (gt_id-1)*0.25
           amp=1.0 if gene_id==1 else 0.35
           val=base + amp*math.sin(2*math.pi*zt/24 + gene_id*0.4) + (rep%2)*0.08
           expr.append((gene_id,sid,val))
          sid+=1
    con.executemany("INSERT INTO samples VALUES (?,?,?,?,?,?,?,?)",samples)
    con.executemany("INSERT INTO expression VALUES (?,?,?)",expr)
    coeff=[]
    for gene_id in (1,2,3):
     for cluster_id in (1,2,3):
      for age_id in (1,2):
       for sex_id in (1,2):
        for gt_id in (1,2):
         intercept=6 + gene_id*0.7 + cluster_id*0.15 + (age_id-1)*0.2 + (gt_id-1)*0.25
         amp=1.0 if gene_id==1 else 0.35
         phase=gene_id*0.4
         coeff.append((gene_id,cluster_id,age_id,sex_id,gt_id,6,intercept,amp*math.cos(phase),amp*math.sin(phase)))
    con.executemany("INSERT INTO model_coefficients VALUES (?,?,?,?,?,?,?,?,?)",coeff)
    con.execute("CREATE TABLE spatial_means (gene_id INTEGER NOT NULL, cluster_id INTEGER NOT NULL, genotype_id INTEGER NOT NULL, age_id INTEGER NOT NULL, mean_value REAL NOT NULL, n_samples INTEGER NOT NULL, PRIMARY KEY(gene_id,cluster_id,genotype_id,age_id)) WITHOUT ROWID")
    for gene_id in (1,2,3):
     for cluster_id in (1,2,3):
      for gt_id in (1,2):
       for age_id in (1,2):
        con.execute("INSERT INTO spatial_means VALUES (?,?,?,?,?,?)",(gene_id,cluster_id,gt_id,age_id,5+gene_id+cluster_id*.2+gt_id*.3+age_id*.1,12))
    con.execute("CREATE TABLE gene_stats (gene_id INTEGER PRIMARY KEY, observation_count INTEGER NOT NULL) WITHOUT ROWID")
    con.executemany("INSERT INTO gene_stats VALUES (?,?)",[(1,len(samples)),(2,len(samples)),(3,len(samples))])

    # Rostral/intermediate/caudal cortical rhythmicity fixture tables in diurnal.sqlite.
    con.execute("INSERT OR REPLACE INTO settings(key,value) VALUES ('rostral_caudal_default_gene','Dbp')")
    con.execute("INSERT OR REPLACE INTO settings(key,value) VALUES ('rostral_caudal_default_cluster','L23')")
    con.execute("CREATE TABLE rc_genes (gene_id INTEGER PRIMARY KEY, symbol TEXT NOT NULL UNIQUE COLLATE NOCASE, symbol_upper TEXT NOT NULL, gene_prefix TEXT, sort_order INTEGER)")
    con.executemany("INSERT INTO rc_genes VALUES (?,?,?,?,?)",[(1,'Dbp','DBP','DB',1),(2,'Hspa5','HSPA5','HS',2),(3,'Lct','LCT','LC',3)])
    con.execute("CREATE TABLE rc_clusters (cluster_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER)")
    con.executemany("INSERT INTO rc_clusters VALUES (?,?,?,?)",[(1,'L23','Cortex Layer 2/3',1),(2,'L4','Cortex Layer 4',2)])
    con.execute("CREATE TABLE rc_regions (region_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, label TEXT NOT NULL, sort_order INTEGER, color TEXT)")
    con.executemany("INSERT INTO rc_regions VALUES (?,?,?,?,?)",[(1,'R','Rostral',1,'#1f77b4'),(2,'M','Intermediate',2,'#ff7f0e'),(3,'C','Caudal',3,'#2ca02c')])
    con.execute("CREATE TABLE rc_samples (sample_id INTEGER PRIMARY KEY, sample_key TEXT NOT NULL UNIQUE, cluster_id INTEGER NOT NULL, region_id INTEGER NOT NULL, sample TEXT, age TEXT, sex TEXT, time_label TEXT, zt REAL)")
    con.execute("CREATE TABLE rc_expression (gene_id INTEGER NOT NULL, sample_id INTEGER NOT NULL, value REAL NOT NULL, PRIMARY KEY(gene_id, sample_id))")
    con.execute("CREATE TABLE rc_model_coefficients (gene_id INTEGER NOT NULL, cluster_id INTEGER NOT NULL, region_id INTEGER NOT NULL, n_samples INTEGER, intercept REAL, age_y_vs_o REAL, sex_m_vs_f REAL, t_c REAL, t_s REAL, PRIMARY KEY(gene_id, cluster_id, region_id))")
    rc_samples=[]; rc_expr=[]; sid2=1
    for cluster_id in (1,2):
      for region_id, region_code in ((1,'R'),(2,'M'),(3,'C')):
       for sex in ('F','M'):
        for age in ('O','Y'):
         for zt in times:
          key=f"rc{sid2:04d}"
          rc_samples.append((sid2,key,cluster_id,region_id,key,age,sex,f"ZT{zt}",float(zt)))
          for gene_id in (1,2,3):
           base=5.8+gene_id*.5+cluster_id*.15+region_id*.18+(age=='Y')*.2+(sex=='M')*.08
           val=base + (.7 if gene_id==1 else .4)*math.sin(2*math.pi*zt/24+region_id*.25)
           rc_expr.append((gene_id,sid2,val))
          sid2+=1
    con.executemany("INSERT INTO rc_samples VALUES (?,?,?,?,?,?,?,?,?)",rc_samples)
    con.executemany("INSERT INTO rc_expression VALUES (?,?,?)",rc_expr)
    rc_coef=[]
    for gene_id in (1,2,3):
      for cluster_id in (1,2):
       for region_id in (1,2,3):
        intercept=5.8+gene_id*.5+cluster_id*.15+region_id*.18
        amp=.7 if gene_id==1 else .4
        phase=region_id*.25
        rc_coef.append((gene_id,cluster_id,region_id,24,intercept,.2,.08,amp*math.cos(phase),amp*math.sin(phase)))
    con.executemany("INSERT INTO rc_model_coefficients VALUES (?,?,?,?,?,?,?,?,?)",rc_coef)
    con.execute("CREATE INDEX idx_rc_genes_upper ON rc_genes(symbol_upper)")
    con.execute("CREATE INDEX idx_rc_expression_gene_sample ON rc_expression(gene_id, sample_id)")
    con.execute("CREATE INDEX idx_rc_samples_cluster_region_time ON rc_samples(cluster_id, region_id, zt, sample_id)")
    con.execute("CREATE INDEX idx_rc_coef_gene_cluster ON rc_model_coefficients(gene_id, cluster_id, region_id)")
    con.commit(); con.close()


def build_rc(path: Path):
    path.unlink(missing_ok=True)
    con = sqlite3.connect(path)
    kv_tables(con, {
        "rostral_caudal_default_gene": "Dbp",
        "rostral_caudal_default_cluster": "L23",
    }, {"schema_name": "fixture_rostral_caudal", "schema_version": "1"})
    con.execute("CREATE TABLE rc_genes (gene_id INTEGER PRIMARY KEY, symbol TEXT NOT NULL UNIQUE COLLATE NOCASE, symbol_upper TEXT NOT NULL, gene_prefix TEXT, sort_order INTEGER)")
    con.executemany("INSERT INTO rc_genes VALUES (?,?,?,?,?)",[(1,'Dbp','DBP','DB',1),(2,'Hspa5','HSPA5','HS',2),(3,'Lct','LCT','LC',3)])
    con.execute("CREATE TABLE rc_clusters (cluster_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER)")
    con.executemany("INSERT INTO rc_clusters VALUES (?,?,?,?)",[(1,'L23','Cortex Layer 2/3',1),(2,'L4','Cortex Layer 4',2)])
    con.execute("CREATE TABLE rc_regions (region_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, label TEXT NOT NULL, sort_order INTEGER, color TEXT)")
    con.executemany("INSERT INTO rc_regions VALUES (?,?,?,?,?)",[(1,'R','Rostral',1,'#1f77b4'),(2,'M','Intermediate',2,'#ff7f0e'),(3,'C','Caudal',3,'#2ca02c')])
    con.execute("CREATE TABLE rc_samples (sample_id INTEGER PRIMARY KEY, sample_key TEXT NOT NULL UNIQUE, cluster_id INTEGER NOT NULL, region_id INTEGER NOT NULL, sample TEXT, age TEXT, sex TEXT, time_label TEXT, zt REAL)")
    con.execute("CREATE TABLE rc_expression (gene_id INTEGER NOT NULL, sample_id INTEGER NOT NULL, value REAL NOT NULL, PRIMARY KEY(gene_id, sample_id))")
    con.execute("CREATE TABLE rc_model_coefficients (gene_id INTEGER NOT NULL, cluster_id INTEGER NOT NULL, region_id INTEGER NOT NULL, n_samples INTEGER, intercept REAL, age_y_vs_o REAL, sex_m_vs_f REAL, t_c REAL, t_s REAL, PRIMARY KEY(gene_id, cluster_id, region_id))")
    times = [0,4,8,12,16,20]
    rc_samples=[]; rc_expr=[]; sid2=1
    for cluster_id in (1,2):
      for region_id, region_code in ((1,'R'),(2,'M'),(3,'C')):
       for sex in ('F','M'):
        for age in ('O','Y'):
         for zt in times:
          key=f"rc{sid2:04d}"
          rc_samples.append((sid2,key,cluster_id,region_id,key,age,sex,f"ZT{zt}",float(zt)))
          for gene_id in (1,2,3):
           base=5.8+gene_id*.5+cluster_id*.15+region_id*.18+(age=='Y')*.2+(sex=='M')*.08
           val=base + (.7 if gene_id==1 else .4)*math.sin(2*math.pi*zt/24+region_id*.25)
           rc_expr.append((gene_id,sid2,val))
          sid2+=1
    con.executemany("INSERT INTO rc_samples VALUES (?,?,?,?,?,?,?,?,?)",rc_samples)
    con.executemany("INSERT INTO rc_expression VALUES (?,?,?)",rc_expr)
    rc_coef=[]
    for gene_id in (1,2,3):
      for cluster_id in (1,2):
       for region_id in (1,2,3):
        intercept=5.8+gene_id*.5+cluster_id*.15+region_id*.18
        amp=.7 if gene_id==1 else .4
        phase=region_id*.25
        rc_coef.append((gene_id,cluster_id,region_id,24,intercept,.2,.08,amp*math.cos(phase),amp*math.sin(phase)))
    con.executemany("INSERT INTO rc_model_coefficients VALUES (?,?,?,?,?,?,?,?,?)",rc_coef)
    con.execute("CREATE INDEX idx_rc_genes_upper ON rc_genes(symbol_upper)")
    con.execute("CREATE INDEX idx_rc_expression_gene_sample ON rc_expression(gene_id, sample_id)")
    con.execute("CREATE INDEX idx_rc_samples_cluster_region_time ON rc_samples(cluster_id, region_id, zt, sample_id)")
    con.execute("CREATE INDEX idx_rc_coef_gene_cluster ON rc_model_coefficients(gene_id, cluster_id, region_id)")
    con.commit(); con.close()


def build_dv(path: Path):
    path.unlink(missing_ok=True)
    con=sqlite3.connect(path)
    kv_tables(con,{"default_gene":"Lct","default_cluster":"DGsg","default_split_by":"none","analysis_group":"WT only","panel_text":"Differential expression results, dorsal-vs-ventral in WT samples.","y_axis_label":"log2(normalized counts)"},{"schema_name":"fixture_dv","schema_version":"1"})
    con.execute("CREATE TABLE genes (gene_id INTEGER PRIMARY KEY, symbol TEXT NOT NULL UNIQUE COLLATE NOCASE, symbol_upper TEXT NOT NULL UNIQUE, gene_prefix TEXT NOT NULL)")
    con.executemany("INSERT INTO genes VALUES (?,?,?,?)",[(1,"Lct","LCT","L"),(2,"Dbp","DBP","D")])
    con.execute("CREATE TABLE clusters (cluster_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER NOT NULL)")
    con.executemany("INSERT INTO clusters VALUES (?,?,?,?)",[(1,"DGsg","Dentate Gyrus granule layer",1),(2,"CA1","CA1",2)])
    con.execute("CREATE TABLE ages (age_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER NOT NULL)")
    con.executemany("INSERT INTO ages VALUES (?,?,?,?)",[(1,"7 months","7 months",1),(2,"14 months","14 months",2)])
    con.execute("CREATE TABLE sexes (sex_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, label TEXT NOT NULL, sort_order INTEGER NOT NULL)")
    con.executemany("INSERT INTO sexes VALUES (?,?,?,?)",[(1,"F","F",1),(2,"M","M",2)])
    con.execute("CREATE TABLE observations (observation_id INTEGER PRIMARY KEY, observation_key TEXT NOT NULL UNIQUE, source_id TEXT NOT NULL, sample TEXT, cluster_id INTEGER NOT NULL, age_id INTEGER, sex_id INTEGER, dv_region TEXT NOT NULL, time_label TEXT, zt REAL)")
    con.execute("CREATE TABLE expression (gene_id INTEGER NOT NULL, observation_id INTEGER NOT NULL, value REAL NOT NULL, PRIMARY KEY(gene_id,observation_id)) WITHOUT ROWID")
    oid=1
    obs=[]; expr=[]
    for cluster_id in (1,2):
     for age_id in (1,2):
      for sex_id in (1,2):
       for region in ("Dorsal","Ventral"):
        for rep in range(5):
         source=f"dv{oid:03d}"
         obs.append((oid,f"{cluster_id}|{source}",source,source,cluster_id,age_id,sex_id,region,"ZT0",0.0))
         for gene_id in (1,2):
          base=7+gene_id*.4+cluster_id*.2+age_id*.1+sex_id*.05
          delta=.8 if (gene_id==1 and region=="Dorsal") else (-.25 if region=="Ventral" else 0)
          expr.append((gene_id,oid,base+delta+rep*.04))
         oid+=1
    con.executemany("INSERT INTO observations VALUES (?,?,?,?,?,?,?,?,?,?)",obs)
    con.executemany("INSERT INTO expression VALUES (?,?,?)",expr)
    con.execute("CREATE TABLE bar_summary (gene_id INTEGER NOT NULL, cluster_id INTEGER NOT NULL, age_id INTEGER, sex_id INTEGER, dv_region TEXT NOT NULL, n_samples INTEGER NOT NULL, mean_value REAL, sd_value REAL, sem_value REAL, mean_norm_count REAL)")
    con.execute("CREATE TABLE deseq_results (result_id INTEGER PRIMARY KEY, gene_id INTEGER NOT NULL, cluster_id INTEGER NOT NULL, analysis_group TEXT, contrast TEXT, numerator_region TEXT, denominator_region TEXT, base_mean REAL, log2_fold_change REAL, lfc_se REAL, stat REAL, p_value REAL, padj REAL, fdr REAL, fdr_lt_0_05 INTEGER NOT NULL DEFAULT 0, fdr_lt_0_10 INTEGER NOT NULL DEFAULT 0, n_dorsal INTEGER, n_ventral INTEGER, n_samples_total INTEGER)")
    con.executemany("INSERT INTO deseq_results VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[
      (1,1,1,"WT only","Dorsal_vs_Ventral","Dorsal","Ventral",120.5,0.82,0.15,5.4,1e-6,2e-5,2e-5,1,1,20,20,40),
      (2,1,2,"WT only","Dorsal_vs_Ventral","Dorsal","Ventral",99.2,0.55,0.18,3.1,.002,.008,.008,1,1,20,20,40),
      (3,2,1,"WT only","Dorsal_vs_Ventral","Dorsal","Ventral",80.1,-0.12,0.16,-.75,.45,.55,.55,0,0,20,20,40),
    ])
    con.commit(); con.close()


def build_supp(path: Path):
    path.unlink(missing_ok=True)
    con=sqlite3.connect(path)
    kv_tables(con,{"default_gene":"Dbp","default_threshold":"0.1","source_order":"[\"S1\",\"S2\",\"S10\",\"S3\",\"S6\"]"},{"schema_name":"fixture_supp","schema_version":"1"})
    con.execute("CREATE TABLE genes (gene_id INTEGER PRIMARY KEY, symbol TEXT NOT NULL UNIQUE COLLATE NOCASE, symbol_upper TEXT NOT NULL UNIQUE)")
    con.executemany("INSERT INTO genes VALUES (?,?,?)",[(1,"Dbp","DBP"),(2,"Lct","LCT")])
    con.execute("CREATE TABLE sources (source_id TEXT PRIMARY KEY, label TEXT NOT NULL, sort_order INTEGER NOT NULL) WITHOUT ROWID")
    con.executemany("INSERT INTO sources VALUES (?,?,?)",[("S1","NTG rhythmic genes",1),("S2","APP23 rhythmic genes",2),("S10","APP23 vs NTG genotype DRGs",3),("S3","Cluster DRGs",4),("S6","Cortex subregion DRGs",5)])
    con.execute("CREATE TABLE rhythmicity_results (result_id INTEGER PRIMARY KEY, gene_id INTEGER NOT NULL, source_id TEXT NOT NULL, table_name TEXT, result_type TEXT, sheet TEXT, sheet_display TEXT, context TEXT, context_display TEXT, cluster_code TEXT, cluster_key TEXT, cluster_display TEXT, comparison TEXT, comparison_display TEXT, genotype TEXT, age TEXT, significance_metric TEXT, significance REAL, significance_text TEXT, pvalue_metric TEXT, p_value REAL, pvalue_text TEXT, amplitude REAL, amplitude_text TEXT, phase_hr REAL, phase_hr_text TEXT, amplitude_2 REAL, amplitude_2_text TEXT, phase_hr_2 REAL, phase_hr_2_text TEXT, detail TEXT, detail_display TEXT, significant_0_1 INTEGER NOT NULL DEFAULT 0)")
    rows=[
      (1,1,"S1","NTG rhythmic genes","Rhythmicity","L2.3","Cortex Layer 2/3","L2.3","Cortex Layer 2/3","L2.3","l23","Cortex Layer 2/3","","","NTG","all","FDR_BH",.002,"0.002","pvalue",.0002,"0.0002",1.12,"1.12",7.4,"7.4",None,"",None,"","baseMean=108.524496927193; t_s=0.134257012034206; t_c=-0.826780070602973","baseMean=108.52; t_s=0.134; t_c=-0.827",1),
      (2,1,"S2","APP23 rhythmic genes","Rhythmicity","L2.3","Cortex Layer 2/3","L2.3","Cortex Layer 2/3","L2.3","l23","Cortex Layer 2/3","","","APP23","all","FDR_BH",.008,"0.008","pvalue",.001,"0.001",.91,".91",8.2,"8.2",None,"",None,"","amp=.91","amp=0.91",1),
      (3,1,"S3","Cluster DRGs","Differential rhythmicity","NTG","NTG","L23 vs CA1","Cortex Layer 2/3 vs CA1","L23","l23","Cortex Layer 2/3","L23_CA1","Cortex Layer 2/3 vs CA1","NTG","all","padj",.03,"0.03","pvalue",.002,".002",1.2,"1.2",6.1,"6.1",.8,".8",10.2,"10.2","test=L23_CA1","test=Cortex Layer 2/3 vs CA1",1),
      (4,2,"S1","NTG rhythmic genes","Rhythmicity","DGsg","Dentate Gyrus granule layer","DGsg","Dentate Gyrus granule layer","DGsg","dgsg","Dentate Gyrus granule layer","","","NTG","all","FDR_BH",.04,"0.04","pvalue",.01,".01",.4,".4",4.3,"4.3",None,"",None,"","baseMean=42.22","baseMean=42.22",1),
    ]
    con.executemany("INSERT INTO rhythmicity_results VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",rows)
    con.commit(); con.close()

build_diurnal(OUT/'diurnal.sqlite')
build_rc(OUT/'rostral_caudal.sqlite')
build_dv(OUT/'dorsal_ventral.sqlite')
build_supp(OUT/'supplemental.sqlite')
print(OUT)
