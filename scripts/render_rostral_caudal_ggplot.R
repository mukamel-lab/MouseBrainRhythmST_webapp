#!/usr/bin/env Rscript
cmd <- commandArgs(FALSE)
file_arg <- grep('^--file=', cmd, value = TRUE)
script_path <- if (length(file_arg)) sub('^--file=', '', file_arg[[1]]) else 'scripts/render_rostral_caudal_ggplot.R'
script_dir <- dirname(normalizePath(script_path, mustWork = FALSE))
core_path <- file.path(script_dir, 'ggplot_render_core.R')
if (!file.exists(core_path)) core_path <- file.path(getwd(), 'scripts', 'ggplot_render_core.R')
source(core_path)
render_rostral_caudal_ggplot(parse_cli_args(commandArgs(trailingOnly = TRUE)))
