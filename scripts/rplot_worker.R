#!/usr/bin/env Rscript

`%||%` <- function(x, y) {
  if (is.null(x) || length(x) == 0 || is.na(x) || identical(x, "")) y else x
}

parse_args <- function(args) {
  out <- list()
  for (arg in args) {
    if (startsWith(arg, "--")) {
      kv <- strsplit(sub("^--", "", arg), "=", fixed = TRUE)[[1]]
      key <- kv[1]
      value <- if (length(kv) > 1) paste(kv[-1], collapse = "=") else ""
      out[[key]] <- value
    }
  }
  out
}

read_job <- function(path) {
  if (!file.exists(path)) stop("Job file disappeared: ", path, call. = FALSE)
  dat <- utils::read.delim(path, header = FALSE, sep = "\t", quote = "", fill = TRUE,
                           stringsAsFactors = FALSE, col.names = c("key", "value"))
  dat <- dat[nzchar(dat$key), , drop = FALSE]
  as.list(stats::setNames(dat$value, dat$key))
}

write_text <- function(path, text) {
  dir.create(dirname(path), recursive = TRUE, showWarnings = FALSE)
  cat(text, file = path)
}

args <- parse_args(commandArgs(trailingOnly = TRUE))
app_root <- normalizePath(args$`app-root` %||% getwd(), mustWork = FALSE)
setwd(app_root)

cache_dir <- file.path(app_root, "cache", "rplots")
jobs_dir <- file.path(cache_dir, "jobs")
tmp_dir <- file.path(cache_dir, "tmp")
heartbeat_file <- file.path(cache_dir, "worker.heartbeat")
log_file <- file.path(cache_dir, "worker.log")

dir.create(jobs_dir, recursive = TRUE, showWarnings = FALSE)
dir.create(tmp_dir, recursive = TRUE, showWarnings = FALSE)
Sys.setenv(TMPDIR = tmp_dir)

core_path <- file.path(app_root, "scripts", "diurnal_ggplot_core.R")
source(core_path)
ensure_diurnal_ggplot_packages()

worker_log <- function(...) {
  msg <- paste0(format(Sys.time(), "%Y-%m-%d %H:%M:%S"), " ", paste(..., collapse = " "), "\n")
  cat(msg)
  cat(msg, file = log_file, append = TRUE)
}

process_job <- function(job_path) {
  running_path <- sub("\\.job$", ".running", job_path)
  if (!file.rename(job_path, running_path)) return(FALSE)
  job <- read_job(running_path)
  done_file <- job$done_file %||% paste0(running_path, ".done")
  error_file <- job$error_file %||% paste0(running_path, ".error")
  script <- job$script %||% ""
  worker_log("processing", basename(running_path), "script=", script)
  ok <- FALSE
  err <- NULL
  tryCatch({
    if (!identical(script, "render_diurnal_ggplot.R")) {
      stop("Unsupported worker script: ", script, call. = FALSE)
    }
    render_diurnal_ggplot(job)
    write_text(done_file, "ok\n")
    ok <<- TRUE
  }, error = function(e) {
    err <<- conditionMessage(e)
    write_text(error_file, paste0(err, "\n"))
  })
  unlink(running_path)
  if (ok) worker_log("done", basename(running_path)) else worker_log("error", basename(running_path), err)
  TRUE
}

worker_log("started app_root=", app_root)
repeat {
  write_text(heartbeat_file, paste0(Sys.getpid(), "\t", as.character(Sys.time()), "\n"))
  jobs <- list.files(jobs_dir, pattern = "\\.job$", full.names = TRUE)
  if (length(jobs)) {
    for (job in jobs) process_job(job)
  }
  Sys.sleep(0.15)
}
