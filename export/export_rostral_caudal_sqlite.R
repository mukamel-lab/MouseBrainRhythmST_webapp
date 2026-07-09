#!/usr/bin/env Rscript

suppressPackageStartupMessages({
  library(DBI)
  library(RSQLite)
  library(dplyr)
  library(purrr)
  library(tibble)
})

args <- commandArgs(trailingOnly = TRUE)
arg_value <- function(name, default = "") {
  prefix <- paste0("--", name, "=")
  hit <- args[startsWith(args, prefix)]
  if (length(hit)) sub(prefix, "", hit[[length(hit)]], fixed = TRUE) else default
}
has_flag <- function(name) any(args == paste0("--", name))

analysis_root <- "/home/agelber/desp1/precast/precast_final_with_ros_caud/analysis2"
coefs_rds <- arg_value("coefs-rds", file.path(analysis_root, "rhythmicity", "coefs_for_plotting.rds"))
output_dir <- arg_value("output-dir", file.path(analysis_root, "new_data_output2"))
output_sqlite <- arg_value("output-sqlite", file.path(output_dir, "rostral_caudal.sqlite"))
overwrite <- has_flag("overwrite")

message("Rostral-caudal rhythmicity SQLite export")
message("  coefficients RDS: ", coefs_rds)
message("  output dir:       ", output_dir)
message("  output sqlite:    ", output_sqlite)

if (!file.exists(coefs_rds)) stop("coefs RDS not found: ", coefs_rds, call. = FALSE)
dir.create(output_dir, recursive = TRUE, showWarnings = FALSE)

if (file.exists(output_sqlite)) {
  if (!overwrite) stop("output sqlite already exists. Use --overwrite: ", output_sqlite, call. = FALSE)
  unlink(output_sqlite)
}

counts_coefs <- readRDS(coefs_rds)
if (!is.list(counts_coefs) || !length(counts_coefs)) stop("coefs_for_plotting.rds did not contain the expected nested list.", call. = FALSE)

cluster_label <- function(x) {
  key <- tolower(gsub("[^A-Za-z0-9]+", "", as.character(x)))
  labels <- c(
    l23 = "Cortex Layer 2/3", l4 = "Cortex Layer 4", l5a = "Cortex Layer 5a",
    l5b = "Cortex Layer 5b", l6a = "Cortex Layer 6a", l6b = "Cortex Layer 6b"
  )
  out <- labels[key]
  ifelse(is.na(out), as.character(x), unname(out))
}

region_tbl <- tibble(
  region_id = 1:3,
  code = c("R", "M", "C"),
  label = c("Rostral", "Intermediate", "Caudal"),
  sort_order = 1:3,
  color = c("#1f77b4", "#ff7f0e", "#2ca02c")
)

clusters <- names(counts_coefs)
cluster_tbl <- tibble(
  cluster_id = seq_along(clusters),
  code = clusters,
  label = cluster_label(clusters),
  sort_order = seq_along(clusters)
)

message("  clusters: ", paste(clusters, collapse = ", "))

all_genes <- character()
for (cl in clusters) {
  for (region in c("R", "M", "C")) {
    entry <- counts_coefs[[cl]][[region]]
    if (is.null(entry)) next
    if (!is.null(entry$counts) && "gene" %in% names(entry$counts)) all_genes <- c(all_genes, as.character(entry$counts$gene))
    if (!is.null(entry$coefs) && "gene" %in% names(entry$coefs)) all_genes <- c(all_genes, as.character(entry$coefs$gene))
  }
}
all_genes <- sort(unique(all_genes[nzchar(all_genes)]))
if (!length(all_genes)) stop("No genes were found in coefs_for_plotting.rds.", call. = FALSE)

gene_tbl <- tibble(
  gene_id = seq_along(all_genes),
  symbol = all_genes,
  symbol_upper = toupper(all_genes),
  gene_prefix = substr(toupper(all_genes), 1, 2),
  sort_order = seq_along(all_genes)
)
gene_id_map <- setNames(gene_tbl$gene_id, gene_tbl$symbol)
cluster_id_map <- setNames(cluster_tbl$cluster_id, cluster_tbl$code)
region_id_map <- setNames(region_tbl$region_id, region_tbl$code)

sample_rows <- list()
expr_rows <- list()
coef_rows <- list()
sample_key_to_id <- new.env(parent = emptyenv())
next_sample_id <- 1L

get_sample_id <- function(key) {
  if (exists(key, envir = sample_key_to_id, inherits = FALSE)) return(get(key, envir = sample_key_to_id, inherits = FALSE))
  id <- next_sample_id
  assign(key, id, envir = sample_key_to_id)
  next_sample_id <<- next_sample_id + 1L
  id
}

for (cl in clusters) {
  for (region in c("R", "M", "C")) {
    entry <- counts_coefs[[cl]][[region]]
    if (is.null(entry)) next
    cluster_id <- unname(cluster_id_map[[cl]])
    region_id <- unname(region_id_map[[region]])

    counts <- entry$counts
    if (!is.null(counts) && nrow(counts)) {
      required <- c("gene", "sample", "age", "sex", "time", "l2expr")
      missing <- setdiff(required, names(counts))
      if (length(missing)) stop("counts for ", cl, "/", region, " missing columns: ", paste(missing, collapse = ", "), call. = FALSE)
      counts <- counts %>%
        mutate(
          gene = as.character(.data$gene),
          sample = as.character(.data$sample),
          age = as.character(.data$age),
          sex = as.character(.data$sex),
          time_label = as.character(.data$time),
          zt = suppressWarnings(as.numeric(gsub("ZT", "", .data$time))),
          l2expr = as.numeric(.data$l2expr)
        ) %>%
        filter(.data$gene %in% all_genes, is.finite(.data$zt), is.finite(.data$l2expr))
      if (nrow(counts)) {
        sample_key <- paste(cl, region, counts$sample, counts$time_label, sep = "|")
        sample_id <- vapply(sample_key, get_sample_id, integer(1))
        sample_rows[[length(sample_rows) + 1L]] <- tibble(
          sample_id = sample_id,
          sample_key = sample_key,
          cluster_id = cluster_id,
          region_id = region_id,
          sample = counts$sample,
          age = counts$age,
          sex = counts$sex,
          time_label = counts$time_label,
          zt = counts$zt
        )
        expr_rows[[length(expr_rows) + 1L]] <- tibble(
          gene_id = unname(gene_id_map[counts$gene]),
          sample_id = sample_id,
          value = counts$l2expr
        )
      }
    }

    coefs <- entry$coefs
    if (!is.null(coefs) && nrow(coefs)) {
      required <- c("gene", "Intercept", "t_c", "t_s")
      missing <- setdiff(required, names(coefs))
      if (length(missing)) stop("coefs for ", cl, "/", region, " missing columns: ", paste(missing, collapse = ", "), call. = FALSE)
      if (!"age_Y_vs_O" %in% names(coefs)) coefs$age_Y_vs_O <- 0
      if (!"sex_M_vs_F" %in% names(coefs)) coefs$sex_M_vs_F <- 0
      coefs <- coefs %>%
        mutate(
          gene = as.character(.data$gene),
          Intercept = as.numeric(.data$Intercept),
          age_Y_vs_O = as.numeric(.data$age_Y_vs_O),
          sex_M_vs_F = as.numeric(.data$sex_M_vs_F),
          t_c = as.numeric(.data$t_c),
          t_s = as.numeric(.data$t_s)
        ) %>%
        filter(.data$gene %in% all_genes)
      if (nrow(coefs)) {
        n_samples <- if (!is.null(counts) && nrow(counts)) dplyr::n_distinct(as.character(counts$sample)) else NA_integer_
        coef_rows[[length(coef_rows) + 1L]] <- tibble(
          gene_id = unname(gene_id_map[coefs$gene]),
          cluster_id = cluster_id,
          region_id = region_id,
          n_samples = n_samples,
          intercept = coefs$Intercept,
          age_y_vs_o = coefs$age_Y_vs_O,
          sex_m_vs_f = coefs$sex_M_vs_F,
          t_c = coefs$t_c,
          t_s = coefs$t_s
        )
      }
    }
  }
}

sample_tbl <- bind_rows(sample_rows) %>% distinct(.data$sample_id, .keep_all = TRUE) %>% arrange(.data$sample_id)
expr_tbl <- bind_rows(expr_rows) %>% filter(!is.na(.data$gene_id), !is.na(.data$sample_id), is.finite(.data$value)) %>% distinct(.data$gene_id, .data$sample_id, .keep_all = TRUE)
coef_tbl <- bind_rows(coef_rows) %>% filter(!is.na(.data$gene_id), is.finite(.data$intercept), is.finite(.data$t_c), is.finite(.data$t_s)) %>% distinct(.data$gene_id, .data$cluster_id, .data$region_id, .keep_all = TRUE)

message("  genes:       ", nrow(gene_tbl))
message("  samples:     ", nrow(sample_tbl))
message("  expression:  ", nrow(expr_tbl))
message("  coefficients:", nrow(coef_tbl))

con <- dbConnect(RSQLite::SQLite(), output_sqlite)
on.exit(dbDisconnect(con), add = TRUE)
dbExecute(con, "PRAGMA foreign_keys = OFF")
dbBegin(con)
tryCatch({
  for (tbl in c("rc_schema_info", "rc_genes", "rc_clusters", "rc_regions", "rc_samples", "rc_expression", "rc_model_coefficients")) {
    dbExecute(con, paste0("DROP TABLE IF EXISTS ", tbl))
  }

  dbExecute(con, "CREATE TABLE rc_schema_info (key TEXT PRIMARY KEY, value TEXT NOT NULL)")
  dbExecute(con, "CREATE TABLE rc_genes (gene_id INTEGER PRIMARY KEY, symbol TEXT NOT NULL UNIQUE, symbol_upper TEXT NOT NULL, gene_prefix TEXT, sort_order INTEGER)")
  dbExecute(con, "CREATE TABLE rc_clusters (cluster_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, label TEXT NOT NULL, sort_order INTEGER)")
  dbExecute(con, "CREATE TABLE rc_regions (region_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, label TEXT NOT NULL, sort_order INTEGER, color TEXT)")
  dbExecute(con, "CREATE TABLE rc_samples (sample_id INTEGER PRIMARY KEY, sample_key TEXT NOT NULL UNIQUE, cluster_id INTEGER NOT NULL, region_id INTEGER NOT NULL, sample TEXT, age TEXT, sex TEXT, time_label TEXT, zt REAL)")
  dbExecute(con, "CREATE TABLE rc_expression (gene_id INTEGER NOT NULL, sample_id INTEGER NOT NULL, value REAL NOT NULL, PRIMARY KEY (gene_id, sample_id))")
  dbExecute(con, "CREATE TABLE rc_model_coefficients (gene_id INTEGER NOT NULL, cluster_id INTEGER NOT NULL, region_id INTEGER NOT NULL, n_samples INTEGER, intercept REAL, age_y_vs_o REAL, sex_m_vs_f REAL, t_c REAL, t_s REAL, PRIMARY KEY (gene_id, cluster_id, region_id))")

  dbWriteTable(con, "rc_schema_info", tibble(key = c("schema_version", "created_at", "source_rds"), value = c("1", format(Sys.time(), "%Y-%m-%dT%H:%M:%SZ", tz = "UTC"), normalizePath(coefs_rds, mustWork = FALSE))), append = TRUE)
  dbWriteTable(con, "rc_genes", gene_tbl, append = TRUE)
  dbWriteTable(con, "rc_clusters", cluster_tbl, append = TRUE)
  dbWriteTable(con, "rc_regions", region_tbl, append = TRUE)
  dbWriteTable(con, "rc_samples", sample_tbl, append = TRUE)
  dbWriteTable(con, "rc_expression", expr_tbl, append = TRUE)
  dbWriteTable(con, "rc_model_coefficients", coef_tbl, append = TRUE)

  dbExecute(con, "CREATE INDEX idx_rc_genes_upper ON rc_genes(symbol_upper)")
  dbExecute(con, "CREATE INDEX idx_rc_genes_prefix ON rc_genes(gene_prefix, symbol_upper)")
  dbExecute(con, "CREATE INDEX idx_rc_samples_cluster_region_time ON rc_samples(cluster_id, region_id, zt, sample_id)")
  dbExecute(con, "CREATE INDEX idx_rc_expression_gene_sample ON rc_expression(gene_id, sample_id)")
  dbExecute(con, "CREATE INDEX idx_rc_expression_sample ON rc_expression(sample_id)")
  dbExecute(con, "CREATE INDEX idx_rc_coef_gene_cluster ON rc_model_coefficients(gene_id, cluster_id, region_id)")

  dbExecute(con, "CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)")
  dbExecute(con, "INSERT OR REPLACE INTO settings(key, value) VALUES ('rostral_caudal_available', 'true')")
  dbExecute(con, "INSERT OR REPLACE INTO settings(key, value) VALUES ('rostral_caudal_default_gene', 'Dbp')")
  dbExecute(con, "INSERT OR REPLACE INTO settings(key, value) VALUES ('rostral_caudal_default_cluster', 'L23')")

  dbCommit(con)
}, error = function(e) {
  dbRollback(con)
  stop(e)
})

dbExecute(con, "ANALYZE")
if (!has_flag("skip-vacuum")) dbExecute(con, "VACUUM")

manifest <- list(
  output_sqlite = normalizePath(output_sqlite, mustWork = FALSE),
  source_rds = normalizePath(coefs_rds, mustWork = FALSE),
  genes = nrow(gene_tbl),
  clusters = nrow(cluster_tbl),
  samples = nrow(sample_tbl),
  expression_rows = nrow(expr_tbl),
  coefficient_rows = nrow(coef_tbl),
  tables = c("rc_genes", "rc_clusters", "rc_regions", "rc_samples", "rc_expression", "rc_model_coefficients")
)
saveRDS(manifest, file.path(output_dir, "rostral_caudal_manifest.rds"))
writeLines(capture.output(str(manifest)), file.path(output_dir, "rostral_caudal_manifest.txt"))

message("Done. Rostral-caudal SQLite database written to: ", output_sqlite)
message("Copy rostral_caudal.sqlite to the deployed Brainome data-private/ directory.")
