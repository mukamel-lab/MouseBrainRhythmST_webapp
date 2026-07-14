#!/usr/bin/env Rscript
cmd <- commandArgs(FALSE)
file_arg <- grep('^--file=', cmd, value = TRUE)
script_path <- if (length(file_arg)) sub('^--file=', '', file_arg[[1]]) else 'scripts/render_diurnal_ggplot.R'
script_dir <- dirname(normalizePath(script_path, mustWork = FALSE))
core_path <- file.path(script_dir, 'diurnal_ggplot_core.R')
if (!file.exists(core_path)) {
  core_path <- file.path(getwd(), 'scripts', 'diurnal_ggplot_core.R')
}
source(core_path)
render_diurnal_ggplot(parse_cli_args(commandArgs(trailingOnly = TRUE)))
