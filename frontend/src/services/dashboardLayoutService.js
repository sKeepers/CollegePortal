import { api } from './api'

export const DASHBOARD_LAYOUT_PROFILE = 'Мой Dashboard'

export const dashboardLayoutService = {
  async list(dashboardType) {
    return api.list('dashboard/layouts', { dashboard_type: dashboardType })
  },

  async save(layoutId, dashboardType, widgets) {
    const payload = {
      dashboard_type: dashboardType,
      name: DASHBOARD_LAYOUT_PROFILE,
      is_default: true,
      layout: {
        version: 2,
        widgets: widgets.map((widget, index) => ({
          id: widget.id,
          order: index,
          size: widget.size || widget.defaultSize || 'medium',
          visible: Boolean(widget.visible),
        })),
      },
    }

    if (layoutId) {
      return api.put(`dashboard/layouts/${layoutId}`, payload)
    }

    return api.create('dashboard/layouts', payload)
  },

  async reset(dashboardType) {
    return api.create('dashboard/layouts/reset', { dashboard_type: dashboardType })
  },
}
