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
        "default_gene": "Dbp", "default_cluster": "L23",
        "default_genotype": "NTG", "default_color_by": "region",
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


def build_nonrhythmic(path: Path):
    """Build a compact schema-v3 cluster-level Wald fixture."""
    path.unlink(missing_ok=True)
    con = sqlite3.connect(path)
    con.executescript(
        """
        PRAGMA foreign_keys=ON;
        CREATE TABLE nr_schema_info (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE nr_genes (gene_id INTEGER PRIMARY KEY, symbol TEXT NOT NULL UNIQUE, symbol_upper TEXT NOT NULL, gene_prefix TEXT, sort_order INTEGER);
        CREATE TABLE nr_clusters (cluster_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, label TEXT NOT NULL, raw_code TEXT NOT NULL UNIQUE, sort_order INTEGER);
        CREATE TABLE nr_ages (age_id INTEGER PRIMARY KEY, label TEXT NOT NULL UNIQUE, role TEXT NOT NULL, sort_order INTEGER);
        CREATE TABLE nr_models (model_id INTEGER PRIMARY KEY, cluster_id INTEGER NOT NULL UNIQUE, design TEXT NOT NULL, fit_type TEXT NOT NULL, reference_age TEXT NOT NULL, comparison_age TEXT NOT NULL, reference_sex TEXT NOT NULL, comparison_sex TEXT NOT NULL, reference_genotype TEXT NOT NULL, comparison_genotype TEXT NOT NULL, n_samples INTEGER NOT NULL, n_genes INTEGER NOT NULL, n_coefficients INTEGER NOT NULL, covariance_method TEXT NOT NULL, FOREIGN KEY(cluster_id) REFERENCES nr_clusters(cluster_id));
        CREATE TABLE nr_model_coefficients (model_id INTEGER NOT NULL, coef_index INTEGER NOT NULL, result_name TEXT NOT NULL, model_matrix_name TEXT NOT NULL, term_name TEXT NOT NULL, PRIMARY KEY(model_id,coef_index), FOREIGN KEY(model_id) REFERENCES nr_models(model_id)) WITHOUT ROWID;
        CREATE TABLE nr_design_cells (cell_id INTEGER PRIMARY KEY, model_id INTEGER NOT NULL, age_id INTEGER NOT NULL, age_label TEXT NOT NULL, sex TEXT NOT NULL, genotype TEXT NOT NULL, sample_n INTEGER NOT NULL, x0 REAL NOT NULL, x1 REAL NOT NULL, x2 REAL NOT NULL, x3 REAL NOT NULL, x4 REAL NOT NULL, x5 REAL NOT NULL, UNIQUE(model_id,age_label,sex,genotype), FOREIGN KEY(model_id) REFERENCES nr_models(model_id), FOREIGN KEY(age_id) REFERENCES nr_ages(age_id));
        CREATE TABLE nr_samples (sample_id INTEGER PRIMARY KEY, sample_key TEXT NOT NULL UNIQUE, model_id INTEGER NOT NULL, sample TEXT NOT NULL, age_id INTEGER NOT NULL, age_label TEXT NOT NULL, sex TEXT NOT NULL, genotype TEXT NOT NULL, time_label TEXT, zt REAL, size_factor REAL NOT NULL, UNIQUE(model_id,sample), FOREIGN KEY(model_id) REFERENCES nr_models(model_id), FOREIGN KEY(age_id) REFERENCES nr_ages(age_id));
        CREATE TABLE nr_expression (gene_id INTEGER NOT NULL, sample_id INTEGER NOT NULL, value REAL NOT NULL, PRIMARY KEY(gene_id,sample_id), FOREIGN KEY(gene_id) REFERENCES nr_genes(gene_id), FOREIGN KEY(sample_id) REFERENCES nr_samples(sample_id)) WITHOUT ROWID;
        CREATE TABLE nr_wald_basis (gene_id INTEGER NOT NULL, model_id INTEGER NOT NULL, base_mean REAL, dispersion REAL, beta_converged INTEGER, max_cooks REAL, beta0 REAL, beta1 REAL, beta2 REAL, beta3 REAL, beta4 REAL, beta5 REAL, cov00 REAL, cov01 REAL, cov02 REAL, cov03 REAL, cov04 REAL, cov05 REAL, cov11 REAL, cov12 REAL, cov13 REAL, cov14 REAL, cov15 REAL, cov22 REAL, cov23 REAL, cov24 REAL, cov25 REAL, cov33 REAL, cov34 REAL, cov35 REAL, cov44 REAL, cov45 REAL, cov55 REAL, PRIMARY KEY(gene_id,model_id), FOREIGN KEY(gene_id) REFERENCES nr_genes(gene_id), FOREIGN KEY(model_id) REFERENCES nr_models(model_id)) WITHOUT ROWID;
        CREATE TABLE nr_contrast_catalog (contrast_id INTEGER PRIMARY KEY, model_id INTEGER NOT NULL, code TEXT NOT NULL, label TEXT NOT NULL, family TEXT NOT NULL, description TEXT NOT NULL, age_mode TEXT NOT NULL, sex_mode TEXT NOT NULL, weighting TEXT NOT NULL, is_default INTEGER NOT NULL, c0 REAL NOT NULL, c1 REAL NOT NULL, c2 REAL NOT NULL, c3 REAL NOT NULL, c4 REAL NOT NULL, c5 REAL NOT NULL, UNIQUE(model_id,code), FOREIGN KEY(model_id) REFERENCES nr_models(model_id));
        CREATE TABLE nr_export_errors (model_id INTEGER, cluster_code TEXT, step TEXT NOT NULL, message TEXT NOT NULL);
        CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        """
    )
    con.executemany(
        "INSERT INTO nr_schema_info(key,value) VALUES (?,?)",
        [
            ("schema_version", "3"),
            ("analysis", "nonrhythmic_APP23_cluster_Wald_basis"),
            ("model", "~ sex + age + genotype + genotype:age + genotype:sex"),
            ("reference_levels", "sex=F;age=7 months;genotype=NTG"),
            ("fit_type", "parametric"),
            ("ages", "7 months,14 months"),
            ("expression_value", "log2(size-factor-normalized count + 1)"),
            ("wald_basis", "6 coefficients plus 21 covariance entries"),
            ("dynamic_test_scope", "one-dimensional numeric Wald contrasts; no live LRT or lfcShrink"),
        ],
    )
    con.executemany(
        "INSERT INTO settings(key,value) VALUES (?,?)",
        [
            ("nonrhythmic_default_gene", "Idi1"),
            ("nonrhythmic_default_cluster", "L23"),
            ("nonrhythmic_default_contrast", "genotype_altage_marginal_sex_equal"),
            ("nonrhythmic_design", "~ sex + age + genotype + genotype:age + genotype:sex"),
        ],
    )
    genes = [
        (1, "humanAPP", "HUMANAPP", "HU", 1),
        (2, "Dbp", "DBP", "DB", 2),
        (3, "Lct", "LCT", "LC", 3),
        (4, "Idi1", "IDI1", "ID", 4),
    ]
    con.executemany("INSERT INTO nr_genes VALUES (?,?,?,?,?)", genes)
    con.executemany(
        "INSERT INTO nr_clusters VALUES (?,?,?,?,?)",
        [
            (1, "L23", "Cortex Layer 2/3", "L2.3", 1),
            (2, "DGsg", "Dentate Gyrus granule layer", "DGsg", 2),
        ],
    )
    con.executemany(
        "INSERT INTO nr_ages VALUES (?,?,?,?)",
        [(1, "7 months", "reference", 1), (2, "14 months", "comparison", 2)],
    )
    design_formula = "~ sex + age + genotype + genotype:age + genotype:sex"
    models = [
        (1, 1, design_formula, "parametric", "7 months", "14 months", "F", "M", "NTG", "APP23", 16, 4, 6, "fixture diagonal covariance"),
        (2, 2, design_formula, "parametric", "7 months", "14 months", "F", "M", "NTG", "APP23", 16, 4, 6, "fixture diagonal covariance"),
    ]
    con.executemany("INSERT INTO nr_models VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", models)
    coefficient_rows = [
        (0, "Intercept", "(Intercept)", "(Intercept)"),
        (1, "sex_M_vs_F", "sexM", "sex"),
        (2, "age_14.months_vs_7.months", "age14 months", "age"),
        (3, "genotype_APP23_vs_NTG", "genotypeAPP23", "genotype"),
        (4, "genotypeAPP23.age14.months", "age14 months:genotypeAPP23", "genotype:age"),
        (5, "genotypeAPP23.sexM", "sexM:genotypeAPP23", "genotype:sex"),
    ]
    con.executemany(
        "INSERT INTO nr_model_coefficients VALUES (?,?,?,?,?)",
        [(model_id, *row) for model_id in (1, 2) for row in coefficient_rows],
    )

    ages = [(1, "7 months"), (2, "14 months")]
    sexes = ("F", "M")
    genotypes = ("NTG", "APP23")

    def design(age_id: int, sex: str, genotype: str):
        is_alt_age = float(age_id == 2)
        is_male = float(sex == "M")
        is_app23 = float(genotype == "APP23")
        return (1.0, is_male, is_alt_age, is_app23, is_alt_age * is_app23, is_male * is_app23)

    def subtract(left, right):
        return tuple(a - b for a, b in zip(left, right))

    def mean_vectors(vectors):
        return tuple(sum(values) / len(vectors) for values in zip(*vectors))

    cell_rows = []
    cell_id = 1
    for model_id in (1, 2):
        for age_id, age_label in ages:
            for sex in sexes:
                for genotype in genotypes:
                    cell_rows.append((cell_id, model_id, age_id, age_label, sex, genotype, 2, *design(age_id, sex, genotype)))
                    cell_id += 1
    con.executemany("INSERT INTO nr_design_cells VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", cell_rows)

    contrast_rows = []
    contrast_id = 1
    for model_id in (1, 2):
        def genotype_effect(age_id: int, sex: str):
            return subtract(design(age_id, sex, "APP23"), design(age_id, sex, "NTG"))

        def add(code, label, family, description, age_mode, sex_mode, weighting, vector, is_default=0):
            nonlocal contrast_id
            contrast_rows.append(
                (contrast_id, model_id, code, label, family, description, age_mode, sex_mode, weighting, is_default, *vector)
            )
            contrast_id += 1

        for age_id, age_label, age_code in ((1, "7 months", "refage"), (2, "14 months", "altage")):
            for sex, sex_code in (("F", "refsex"), ("M", "altsex")):
                add(
                    f"genotype_{age_code}_{sex_code}",
                    f"APP23 vs NTG at {age_label}, sex {sex}",
                    "genotype", "Cell-specific APP23-minus-NTG effect.",
                    age_label, sex, "cell", genotype_effect(age_id, sex),
                )
        for sex in sexes:
            vector = mean_vectors([genotype_effect(1, sex), genotype_effect(2, sex)])
            add(f"genotype_marginal_age_equal_{sex.lower()}", f"APP23 vs NTG, age-marginalized equally, sex {sex}", "genotype", "Equal-weight age marginal effect.", "marginal", sex, "equal", vector)
            add(f"genotype_marginal_age_observed_{sex.lower()}", f"APP23 vs NTG, age-marginalized by observed n, sex {sex}", "genotype", "Observed-sample-weight age marginal effect.", "marginal", sex, "observed", vector)
        for age_id, age_label, age_code in ((1, "7 months", "refage"), (2, "14 months", "altage")):
            vector = mean_vectors([genotype_effect(age_id, "F"), genotype_effect(age_id, "M")])
            add(f"genotype_{age_code}_marginal_sex_equal", f"APP23 vs NTG at {age_label}, sex-marginalized equally", "genotype", "Equal-weight sex marginal effect.", age_label, "marginal", "equal", vector, int(age_id == 2))
            add(f"genotype_{age_code}_marginal_sex_observed", f"APP23 vs NTG at {age_label}, sex-marginalized by observed n", "genotype", "Observed-sample-weight sex marginal effect.", age_label, "marginal", "observed", vector)
        joint = [genotype_effect(age_id, sex) for age_id, _ in ages for sex in sexes]
        joint_vector = mean_vectors(joint)
        add("genotype_marginal_age_sex_equal", "APP23 vs NTG, equally marginalized over age and sex", "genotype", "Equal-weight average of four age/sex effects.", "marginal", "marginal", "equal", joint_vector)
        add("genotype_marginal_age_sex_observed", "APP23 vs NTG, observed-distribution marginal effect", "genotype", "Standardized to pooled observed age/sex distribution.", "marginal", "marginal", "observed", joint_vector)
        add("genotype_by_age", "Genotype-by-age: 14 months minus 7 months", "interaction", "Difference-in-differences across age.", "interaction", "model-common", "none", subtract(genotype_effect(2, "F"), genotype_effect(1, "F")))
        add("genotype_by_sex", "Genotype-by-sex: M minus F", "interaction", "Difference-in-differences across sex.", "model-common", "interaction", "none", subtract(genotype_effect(1, "M"), genotype_effect(1, "F")))
        for genotype in genotypes:
            add(f"age_alt_vs_ref_{genotype.lower()}_refsex", f"14 months vs 7 months in {genotype}, F", "age", "Age effect at reference sex.", "comparison-vs-reference", "F", "cell", subtract(design(2, "F", genotype), design(1, "F", genotype)))
            add(f"sex_alt_vs_ref_{genotype.lower()}_refage", f"M vs F in {genotype}, 7 months", "sex", "Sex effect at reference age.", "7 months", "comparison-vs-reference", "cell", subtract(design(1, "M", genotype), design(1, "F", genotype)))
    con.executemany("INSERT INTO nr_contrast_catalog VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", contrast_rows)

    beta_by_model = {
        1: [
            (6.20, 0.10, 0.25, 1.00, 0.50, -0.20),
            (6.80, -0.08, 0.15, 0.30, -0.10, 0.10),
            (5.70, 0.12, -0.30, -0.80, -0.20, 0.40),
            (7.10, 0.04, 0.05, 0.03, 0.05, -0.02),
        ],
        2: [
            (6.00, 0.08, 0.20, 0.70, 0.20, -0.10),
            (6.60, -0.05, 0.10, 0.20, -0.05, 0.04),
            (5.50, 0.15, -0.20, -0.55, -0.15, 0.25),
            (6.90, 0.02, 0.03, 0.01, 0.03, -0.01),
        ],
    }
    covariance_pairs = [(i, j) for i in range(6) for j in range(i, 6)]
    basis_rows = []
    for model_id, gene_betas in beta_by_model.items():
        for gene_id, betas in enumerate(gene_betas, start=1):
            covariance = [0.04 if i == j else 0.0 for i, j in covariance_pairs]
            basis_rows.append((gene_id, model_id, 80.0 + 15.0 * gene_id, 0.08 + 0.01 * gene_id, 1, 0.25 + 0.02 * gene_id, *betas, *covariance))
    con.executemany(
        "INSERT INTO nr_wald_basis VALUES (" + ",".join(["?"] * 33) + ")",
        basis_rows,
    )

    sample_rows = []
    expression_rows = []
    sample_id = 1
    residuals = (-0.12, 0.12)
    for model_id in (1, 2):
        cluster_code = "L23" if model_id == 1 else "DGsg"
        for age_id, age_label in ages:
            for sex in sexes:
                for genotype in genotypes:
                    cell_design = design(age_id, sex, genotype)
                    for replicate, residual in enumerate(residuals, start=1):
                        sample = f"{cluster_code}_{age_id}_{sex}_{genotype}_{replicate}"
                        zt = 2.0 if replicate == 1 else 14.0
                        sample_rows.append((sample_id, f"{cluster_code}|{sample}", model_id, sample, age_id, age_label, sex, genotype, f"ZT{int(zt)}", zt, 0.95 + 0.05 * replicate))
                        for gene_id, betas in enumerate(beta_by_model[model_id], start=1):
                            fitted = sum(value * beta for value, beta in zip(cell_design, betas))
                            expression_rows.append((gene_id, sample_id, fitted + residual + 0.01 * gene_id))
                        sample_id += 1
    con.executemany("INSERT INTO nr_samples VALUES (?,?,?,?,?,?,?,?,?,?,?)", sample_rows)
    con.executemany("INSERT INTO nr_expression VALUES (?,?,?)", expression_rows)
    con.executescript(
        """
        CREATE INDEX idx_nr_genes_upper ON nr_genes(symbol_upper);
        CREATE INDEX idx_nr_genes_prefix ON nr_genes(gene_prefix,symbol_upper);
        CREATE INDEX idx_nr_samples_facets ON nr_samples(model_id,age_id,sex,genotype,sample_id);
        CREATE INDEX idx_nr_samples_name ON nr_samples(sample);
        CREATE INDEX idx_nr_wald_model_gene ON nr_wald_basis(model_id,gene_id);
        CREATE INDEX idx_nr_catalog_model_family ON nr_contrast_catalog(model_id,family,contrast_id);
        """
    )
    con.commit()
    con.close()

build_diurnal(OUT/'diurnal.sqlite')
build_rc(OUT/'rostral_caudal.sqlite')
build_dv(OUT/'dorsal_ventral.sqlite')
build_supp(OUT/'supplemental.sqlite')
build_nonrhythmic(OUT/'nonrhythmic_app23_wald.sqlite')
print(OUT)
